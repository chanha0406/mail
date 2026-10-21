<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 YouCha
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\BackgroundJob;

use OCA\Mail\Account;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\BodyCacheService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches bodies of recent messages ahead of time (self-hosted fork).
 * One IMAP login per account and run; each run stops after a time budget
 * and the next run continues with what is left.
 */
class BodyCacheJob extends TimedJob {
	private const BUDGET_SECONDS = 120;
	private const BATCH = 200;

	public function __construct(
		ITimeFactory $time,
		private BodyCacheService $cache,
		private AccountService $accounts,
		private MailboxMapper $mailboxes,
		private MessageMapper $messages,
		private IMAPClientFactory $clientFactory,
		private IMailManager $mailManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		if ($this->cache->adminLimits()['maxDays'] === 0) {
			$this->cache->deleteAll();
			return;
		}
		$this->cache->deleteOrphans();
		$this->cache->deleteDuplicates();
		$deadline = $this->time->getTime() + self::BUDGET_SECONDS;
		foreach ($this->cache->users() as $userId) {
			$this->cache->prune($userId);
			if ($this->time->getTime() >= $deadline) {
				continue;
			}
			$this->fetchForUser($userId, $deadline);
		}
	}

	private function fetchForUser(string $userId, int $deadline): void {
		$byAccount = [];
		foreach ($this->cache->candidates($userId, self::BATCH) as $row) {
			$byAccount[$row['account_id']][] = $row;
		}
		foreach ($byAccount as $accountId => $rows) {
			if ($this->time->getTime() >= $deadline) {
				return;
			}
			try {
				$account = $this->accounts->findById($accountId);
				$client = $this->clientFactory->getClient($account);
			} catch (Throwable $e) {
				$this->logger->info('Body prefetch skipped account {accountId}: {message}', [
					'accountId' => $accountId,
					'message' => $e->getMessage(),
				]);
				continue;
			}
			try {
				$this->fetchForAccount($userId, $account, $client, $rows, $deadline);
			} finally {
				$client->logout();
			}
		}
	}

	/**
	 * @param list<array{id: int, mailbox_id: int, account_id: int}> $rows
	 */
	private function fetchForAccount(string $userId, Account $account, \Horde_Imap_Client_Socket $client, array $rows, int $deadline): void {
		$mailboxes = [];
		foreach ($rows as $row) {
			if ($this->time->getTime() >= $deadline) {
				return;
			}
			try {
				$message = $this->messages->findByIds($userId, [$row['id']], 'DESC')[0] ?? null;
				if ($message === null) {
					continue;
				}
				$mailboxes[$row['mailbox_id']] ??= $this->mailboxes->findById($row['mailbox_id']);
				$imapMessage = $this->mailManager->getImapMessage($client, $account, $mailboxes[$row['mailbox_id']], $message->getUid(), true);
				if (!$this->cache->put($userId, $account->getId(), $message, $imapMessage)) {
					// Encrypted, outside the window by now, or no room left
					$this->cache->markFailed($userId, $account->getId(), $message);
				}
			} catch (\Horde_Imap_Client_Exception $e) {
				if ($e->getCode() === \Horde_Imap_Client_Exception::LOGIN_AUTHENTICATIONFAILED) {
					// Login is failing: leave the account alone until the next run
					return;
				}
				$this->failed($userId, $account, $row, $e);
			} catch (Throwable $e) {
				$this->failed($userId, $account, $row, $e);
			}
		}
	}

	/**
	 * @param array{id: int, mailbox_id: int, account_id: int} $row
	 */
	private function failed(string $userId, Account $account, array $row, Throwable $e): void {
		$this->logger->debug('Body prefetch failed for message {id}: {message}', [
			'id' => $row['id'],
			'message' => $e->getMessage(),
		]);
		$message = $this->messages->findByIds($userId, [$row['id']], 'DESC')[0] ?? null;
		if ($message !== null) {
			$this->cache->markFailed($userId, $account->getId(), $message);
		}
	}
}
