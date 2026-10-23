<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 YouCha
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service;

use OCA\Mail\AppInfo\Application;
use OCA\Mail\Db\Message;
use OCA\Mail\Model\IMAPMessage;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Message bodies fetched ahead of time (self-hosted fork).
 *
 * Opening a message normally logs in to IMAP and fetches the body every
 * time, which is slow on Gmail. Bodies of recent messages are kept here, keyed
 * by mail_messages.id, so they go away with the message row when it vanishes
 * on the server. Administrators set the per-user maximum days and size; each
 * user picks values up to those limits. -1 means unlimited for both days
 * and size, 0 days turns prefetching off.
 *
 * Messages in an account's archive folder ("보관"), starred messages and
 * messages marked important are pinned: their bodies
 * are fetched whatever their age and never dropped for age or size. They
 * still count in the usage, and new unpinned messages stop when the limit
 * is reached.
 */
class BodyCacheService {
	public const ADMIN_DAYS = 'body_cache_max_days';
	public const ADMIN_MB = 'body_cache_max_mb';
	private const USER_DAYS = 'body_cache_days';
	private const USER_MB = 'body_cache_mb';
	public const UNLIMITED = -1;
	/** Body was stored. */
	public const PUT_STORED = 1;
	/** Already cached, the same Message-ID, or outside the window. */
	public const PUT_SKIP = 2;
	/** Encrypted, or the body cannot be encoded. Do not try again. */
	public const PUT_EXCLUDED = 3;
	/** Size limit reached. Stop filling this account; do not remember the message. */
	public const PUT_FULL = 4;
	private const TABLE = 'mail_body_cache';

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{maxDays: int, maxMb: int}
	 */
	public function adminLimits(): array {
		return [
			'maxDays' => $this->appConfig->getValueInt(Application::APP_ID, self::ADMIN_DAYS, 0),
			'maxMb' => $this->appConfig->getValueInt(Application::APP_ID, self::ADMIN_MB, 200),
		];
	}

	public function setAdminLimits(int $maxDays, int $maxMb): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::ADMIN_DAYS, self::normalize($maxDays));
		$this->appConfig->setValueInt(Application::APP_ID, self::ADMIN_MB, self::normalize($maxMb));
		$this->clearFailed(null);
	}

	/**
	 * The user's values, never above the admin limits. Unset values follow
	 * the limits, so turning the feature on applies to everyone.
	 *
	 * @return array{days: int, mb: int}
	 */
	public function userLimits(string $userId): array {
		$admin = $this->adminLimits();
		$days = $this->config->getUserValue($userId, Application::APP_ID, self::USER_DAYS, '');
		$mb = $this->config->getUserValue($userId, Application::APP_ID, self::USER_MB, '');
		return [
			'days' => self::cap($days === '' ? $admin['maxDays'] : (int)$days, $admin['maxDays']),
			'mb' => self::cap($mb === '' ? $admin['maxMb'] : (int)$mb, $admin['maxMb']),
		];
	}

	/**
	 * Stores the user's choice as given; userLimits() applies the admin
	 * limits, so -1 follows a later change of those limits.
	 */
	public function setUserLimits(string $userId, int $days, int $mb): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_DAYS, (string)self::normalize($days));
		$this->config->setUserValue($userId, Application::APP_ID, self::USER_MB, (string)self::normalize($mb));
		$this->clearFailed($userId);
		$this->prune($userId);
	}

	/**
	 * New limits may let skipped messages fit, so try them again.
	 */
	private function clearFailed(?string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->isNull('data'));
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		$qb->executeStatement();
	}

	/**
	 * Stored bodies of pinned messages (archive folder, starred, important).
	 *
	 * @return array{count: int, bytes: int}
	 */
	public function pinnedUsage(string $userId): array {
		$pinned = $this->pinnedKeys($userId);
		$count = 0;
		$bytes = 0;
		foreach ($this->rowsOf($userId, null) as $row) {
			if (isset($pinned[$row['key']])) {
				$count++;
				$bytes += $row['size'];
			}
		}
		return ['count' => $count, 'bytes' => $bytes];
	}

	/**
	 * @return array{count: int, bytes: int}
	 */
	public function usage(?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'c'), $qb->func()->sum('size'))
			->from(self::TABLE)
			->where($qb->expr()->isNotNull('data'));
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		$row = $qb->executeQuery()->fetch(\PDO::FETCH_NUM);
		return ['count' => (int)($row[0] ?? 0), 'bytes' => (int)($row[1] ?? 0)];
	}

	/**
	 * @return array{full: array<string, mixed>, html: ?string, encrypted: bool, signed: bool, signatureValid: ?bool}|null
	 */
	public function get(int $messageId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('data')
			->from(self::TABLE)
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false || $row['data'] === null) {
			return null;
		}
		$data = is_resource($row['data']) ? stream_get_contents($row['data']) : (string)$row['data'];
		$json = @gzdecode($data);
		$decoded = $json === false ? null : json_decode($json, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * The stored body of this message or of another copy of it in the same
	 * account. Gmail shows one message in INBOX, All Mail and label folders,
	 * and only one copy is stored.
	 *
	 * @return array{full: array<string, mixed>, html: ?string, encrypted: bool, signed: bool, signatureValid: ?bool}|null
	 */
	public function find(Message $message, int $accountId): ?array {
		$stored = $this->get($message->getId());
		if ($stored !== null) {
			return $stored;
		}
		$copy = $this->copyOf($accountId, (string)$message->getMessageId());
		return $copy === null ? null : $this->get($copy);
	}

	public function has(int $messageId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));
		return $qb->executeQuery()->fetch() !== false;
	}

	/**
	 * Keeps the body when the message is inside the user's window and fits
	 * the user's size limit. Encrypted messages are never stored.
	 *
	 * @return int PUT_STORED, PUT_SKIP, PUT_EXCLUDED or PUT_FULL
	 */
	public function put(string $userId, int $accountId, Message $message, IMAPMessage $imapMessage): int {
		$limits = $this->userLimits($userId);
		if ($limits['days'] === 0 || $this->has($message->getId())
			|| $this->copyOf($accountId, (string)$message->getMessageId()) !== null) {
			return self::PUT_SKIP;
		}
		$pinned = $this->isPinned($accountId, $message);
		if (!$pinned && !$this->inWindow($message, $limits['days'])) {
			return self::PUT_SKIP;
		}
		if ($imapMessage->isEncrypted() || $message->isEncrypted() || ($imapMessage->jsonSerialize()['isPgpMimeEncrypted'] ?? false)) {
			return self::PUT_EXCLUDED;
		}
		$payload = [
			'full' => $imapMessage->getFullMessage($message->getId()),
			'html' => $imapMessage->hasHtmlMessage() ? $imapMessage->getHtmlBody($message->getId()) : null,
			'encrypted' => false,
			'signed' => $imapMessage->isSigned(),
			'signatureValid' => $imapMessage->isSigned() ? $imapMessage->isSignatureValid() : null,
		];
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			return self::PUT_EXCLUDED;
		}
		$data = gzencode($json, 6);
		if ($data === false) {
			return self::PUT_EXCLUDED;
		}
		// Pinned bodies are kept even when only they fill the limit
		if (!$this->makeRoom($userId, strlen($data), $limits['mb'], (int)$message->getSentAt()) && !$pinned) {
			return self::PUT_FULL;
		}
		$this->insert($userId, $accountId, $message, $data);
		return self::PUT_STORED;
	}

	/**
	 * Remembers that a message could not be fetched so the job does not try
	 * it on every run. It is dropped with the window like any other row.
	 */
	public function markFailed(string $userId, int $accountId, Message $message): void {
		if (!$this->has($message->getId())) {
			$this->insert($userId, $accountId, $message, null);
		}
	}

	/**
	 * Drops rows outside the user's window and above the size limit.
	 */
	public function prune(string $userId): void {
		$limits = $this->userLimits($userId);
		if ($limits['days'] === 0) {
			// Turned off: everything goes, pinned bodies too
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
			return;
		}
		if ($limits['days'] !== self::UNLIMITED) {
			$pinned = $this->pinnedKeys($userId);
			$drop = [];
			foreach ($this->rowsOf($userId, $this->since($limits['days'])) as $row) {
				if (!isset($pinned[$row['key']])) {
					$drop[] = $row['id'];
				}
			}
			$this->deleteIds($drop);
		}
		$this->makeRoom($userId, 0, $limits['mb']);
	}

	public function deleteAll(): void {
		$this->db->getQueryBuilder()->delete(self::TABLE)->executeStatement();
	}

	/**
	 * Rows whose message is gone: deleted on the server, moved, or the
	 * mailbox was reset.
	 */
	public function deleteOrphans(): int {
		$select = $this->db->getQueryBuilder();
		$select->select('c.id')
			->from(self::TABLE, 'c')
			->leftJoin('c', 'mail_messages', 'm', $select->expr()->eq('m.id', 'c.message_id'))
			->where($select->expr()->isNull('m.id'));
		$ids = array_map('intval', $select->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		foreach (array_chunk($ids, 500) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete(self::TABLE)
				->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$delete->executeStatement();
		}
		return count($ids);
	}

	/**
	 * Messages of the user's accounts inside the window that are not stored
	 * yet, newest first. Trash and junk are skipped.
	 *
	 * @return list<array{id: int, mailbox_id: int, account_id: int}>
	 */
	public function candidates(string $userId, int $limit): array {
		$limits = $this->userLimits($userId);
		if ($limits['days'] === 0 || $limits['mb'] === 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id', 'm.mailbox_id', 'm.message_id', 'b.account_id')
			->from('mail_messages', 'm')
			->join('m', 'mail_mailboxes', 'b', $qb->expr()->eq('b.id', 'm.mailbox_id'))
			->join('b', 'mail_accounts', 'a', $qb->expr()->eq('a.id', 'b.account_id'))
			->leftJoin('m', self::TABLE, 'c', $qb->expr()->eq('c.message_id', 'm.id'))
			->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('c.id'))
			// Not another copy of a stored message (Gmail labels). Done in SQL so
			// a page full of such copies cannot hide the rest.
			->andWhere($qb->createFunction("(m.message_id IS NULL OR m.message_id = '' OR NOT EXISTS (SELECT 1 FROM *PREFIX*" . self::TABLE . ' s JOIN *PREFIX*mail_messages sm ON sm.id = s.message_id WHERE s.account_id = b.account_id AND sm.message_id = m.message_id))'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('b.special_use'),
				$qb->expr()->andX(
					$qb->expr()->notLike('b.special_use', $qb->createNamedParameter('%trash%')),
					$qb->expr()->notLike('b.special_use', $qb->createNamedParameter('%junk%')),
				),
			))
			// Pinned (archive folder, starred, important) first, then newest
			->orderBy($qb->createFunction('CASE WHEN b.id = a.archive_mailbox_id OR m.flag_flagged OR m.flag_important THEN 0 ELSE 1 END'), 'ASC')
			->addOrderBy('m.sent_at', 'DESC')
			// INBOX and real folders before Gmail's All Mail, so that copy is stored
			->addOrderBy($qb->createFunction("CASE WHEN b.special_use LIKE '%all%' THEN 1 ELSE 0 END"), 'ASC')
			->setMaxResults($limit * 3);
		if ($limits['days'] !== self::UNLIMITED) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->gte('m.sent_at', $qb->createNamedParameter($this->since($limits['days']), IQueryBuilder::PARAM_INT)),
				$this->pinnedCondition($qb),
			));
		}
		$stored = $this->storedMessageIds($userId);
		$rows = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$key = self::copyKey((int)$row['account_id'], (string)($row['message_id'] ?? ''));
			if ($key !== null) {
				if (isset($stored[$key])) {
					continue;
				}
				$stored[$key] = true;
			}
			$rows[] = ['id' => (int)$row['id'], 'mailbox_id' => (int)$row['mailbox_id'], 'account_id' => (int)$row['account_id']];
			if (count($rows) >= $limit) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Removes stored rows that are another copy of an already stored message
	 * in the same account; the row of a non-All-Mail folder is kept.
	 */
	public function deleteDuplicates(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.account_id', 'm.message_id', 'b.special_use')
			->from(self::TABLE, 'c')
			->join('c', 'mail_messages', 'm', $qb->expr()->eq('m.id', 'c.message_id'))
			->join('m', 'mail_mailboxes', 'b', $qb->expr()->eq('b.id', 'm.mailbox_id'))
			->orderBy('c.account_id')
			->addOrderBy('m.message_id')
			->addOrderBy($qb->createFunction("CASE WHEN b.special_use LIKE '%all%' THEN 1 ELSE 0 END"), 'ASC')
			->addOrderBy('c.id');
		$seen = [];
		$drop = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$key = self::copyKey((int)$row['account_id'], (string)($row['message_id'] ?? ''));
			if ($key === null) {
				continue;
			}
			if (isset($seen[$key])) {
				$drop[] = (int)$row['id'];
			}
			$seen[$key] = true;
		}
		foreach (array_chunk($drop, 500) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete(self::TABLE)
				->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$delete->executeStatement();
		}
		return count($drop);
	}

	/**
	 * @return list<string>
	 */
	public function users(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from('mail_accounts');
		return array_map('strval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}

	/**
	 * mail_messages.id of a stored copy with this Message-ID in the account.
	 */
	private function copyOf(int $accountId, string $messageIdHeader): ?int {
		if (self::copyKey($accountId, $messageIdHeader) === null) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.message_id')
			->from(self::TABLE, 'c')
			->join('c', 'mail_messages', 'm', $qb->expr()->eq('m.id', 'c.message_id'))
			->where($qb->expr()->eq('c.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.message_id', $qb->createNamedParameter($messageIdHeader)))
			->setMaxResults(1);
		$id = $qb->executeQuery()->fetchOne();
		return $id === false ? null : (int)$id;
	}

	/**
	 * account + Message-ID of every stored row of the user.
	 *
	 * @return array<string, true>
	 */
	private function storedMessageIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.account_id', 'm.message_id')
			->from(self::TABLE, 'c')
			->join('c', 'mail_messages', 'm', $qb->expr()->eq('m.id', 'c.message_id'))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));
		$keys = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$key = self::copyKey((int)$row['account_id'], (string)($row['message_id'] ?? ''));
			if ($key !== null) {
				$keys[$key] = true;
			}
		}
		return $keys;
	}

	private static function copyKey(int $accountId, string $messageIdHeader): ?string {
		$messageIdHeader = trim($messageIdHeader);
		return $messageIdHeader === '' ? null : $accountId . "\0" . $messageIdHeader;
	}

	private function insert(string $userId, int $accountId, Message $message, ?string $data): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'message_id' => $qb->createNamedParameter($message->getId(), IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId),
				'account_id' => $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT),
				'sent_at' => $qb->createNamedParameter((int)$message->getSentAt(), IQueryBuilder::PARAM_INT),
				'size' => $qb->createNamedParameter($data === null ? 0 : strlen($data), IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
				'data' => $qb->createNamedParameter($data, $data === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_LOB),
			]);
		try {
			$qb->executeStatement();
		} catch (\OCP\DB\Exception $e) {
			// Stored meanwhile by the job or another request
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * Deletes the user's oldest rows until $needed more bytes fit under $mb.
	 * With $olderThan only rows older than that are dropped, so storing an
	 * old message never evicts newer ones.
	 */
	private function makeRoom(string $userId, int $needed, int $mb, ?int $olderThan = null): bool {
		if ($mb === self::UNLIMITED) {
			return true;
		}
		$max = $mb * 1024 * 1024;
		if ($needed > $max) {
			return false;
		}
		$used = $this->usage($userId)['bytes'];
		if ($used + $needed <= $max) {
			return true;
		}
		$pinned = $this->pinnedKeys($userId);
		$rows = $this->rowsOf($userId, $olderThan);
		$drop = [];
		foreach ($rows as $row) {
			if ($used + $needed <= $max) {
				break;
			}
			if ($row['size'] === 0 || isset($pinned[$row['key']])) {
				continue;
			}
			$drop[] = $row['id'];
			$used -= $row['size'];
		}
		if ($used + $needed > $max && $needed > 0) {
			// Would not fit anyway: keep the older bodies
			return false;
		}
		// When pruning, drop what can go even if pinned bodies alone are over
		$this->deleteIds($drop);
		return $used + $needed <= $max;
	}

	/**
	 * SQL condition for a pinned message row: in the account's archive folder,
	 * starred, or marked important. Needs aliases m (messages), b (mailboxes)
	 * and a (accounts).
	 */
	private function pinnedCondition(IQueryBuilder $qb): \OCP\DB\QueryBuilder\ICompositeExpression {
		return $qb->expr()->orX(
			$qb->expr()->eq('b.id', 'a.archive_mailbox_id'),
			$qb->expr()->eq('m.flag_flagged', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
			$qb->expr()->eq('m.flag_important', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
		);
	}

	/**
	 * The message, or another copy of it (same Message-ID) in the account, is
	 * pinned.
	 */
	private function isPinned(int $accountId, Message $message): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id')
			->from('mail_messages', 'm')
			->join('m', 'mail_mailboxes', 'b', $qb->expr()->eq('b.id', 'm.mailbox_id'))
			->join('b', 'mail_accounts', 'a', $qb->expr()->eq('a.id', 'b.account_id'))
			->where($qb->expr()->eq('a.id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($this->pinnedCondition($qb))
			->setMaxResults(1);
		$header = trim((string)$message->getMessageId());
		if ($header === '') {
			$qb->andWhere($qb->expr()->eq('m.id', $qb->createNamedParameter($message->getId(), IQueryBuilder::PARAM_INT)));
		} else {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->eq('m.id', $qb->createNamedParameter($message->getId(), IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('m.message_id', $qb->createNamedParameter($header)),
			));
		}
		return $qb->executeQuery()->fetchOne() !== false;
	}

	/**
	 * Keys (see rowsOf) of the pinned messages of the user's accounts.
	 *
	 * @return array<string, true>
	 */
	private function pinnedKeys(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id', 'm.message_id', 'a.id AS account_id')
			->from('mail_messages', 'm')
			->join('m', 'mail_mailboxes', 'b', $qb->expr()->eq('b.id', 'm.mailbox_id'))
			->join('b', 'mail_accounts', 'a', $qb->expr()->eq('a.id', 'b.account_id'))
			->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
			->andWhere($this->pinnedCondition($qb));
		$keys = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$keys[self::rowKey((int)$row['account_id'], (string)($row['message_id'] ?? ''), (int)$row['id'])] = true;
		}
		return $keys;
	}

	/**
	 * The user's stored rows, oldest first, optionally only those sent before
	 * $olderThan. Rows whose message row is already gone are left out.
	 *
	 * @return list<array{id: int, size: int, key: string}>
	 */
	private function rowsOf(string $userId, ?int $olderThan): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.size', 'c.account_id', 'c.message_id AS row_id', 'm.message_id')
			->from(self::TABLE, 'c')
			->join('c', 'mail_messages', 'm', $qb->expr()->eq('m.id', 'c.message_id'))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
			->orderBy('c.sent_at', 'ASC');
		if ($olderThan !== null) {
			$qb->andWhere($qb->expr()->lt('c.sent_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)));
		}
		$rows = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$rows[] = [
				'id' => (int)$row['id'],
				'size' => (int)$row['size'],
				'key' => self::rowKey((int)$row['account_id'], (string)($row['message_id'] ?? ''), (int)$row['row_id']),
			];
		}
		return $rows;
	}

	/**
	 * account + Message-ID, or the message row for mail without a Message-ID.
	 */
	private static function rowKey(int $accountId, string $messageIdHeader, int $rowId): string {
		return self::copyKey($accountId, $messageIdHeader) ?? 'row:' . $rowId;
	}

	/**
	 * @param list<int> $ids
	 */
	private function deleteIds(array $ids): void {
		foreach (array_chunk($ids, 500) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete(self::TABLE)
				->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$delete->executeStatement();
		}
	}

	private function inWindow(Message $message, int $days): bool {
		return $days === self::UNLIMITED || ($days > 0 && (int)$message->getSentAt() >= $this->since($days));
	}

	private function since(int $days): int {
		return $this->time->getTime() - $days * 86400;
	}

	/**
	 * Negative values mean unlimited.
	 */
	private static function normalize(int $value): int {
		return $value < 0 ? self::UNLIMITED : $value;
	}

	/**
	 * A user value limited by the admin value; -1 is unlimited on both sides.
	 */
	private static function cap(int $value, int $limit): int {
		$value = self::normalize($value);
		if ($limit === self::UNLIMITED) {
			return $value;
		}
		return $value === self::UNLIMITED ? $limit : min($value, $limit);
	}
}
