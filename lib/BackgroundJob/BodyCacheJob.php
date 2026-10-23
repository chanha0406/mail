<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 YouCha
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\BackgroundJob;

use Horde_Imap_Client_Exception;
use Horde_Imap_Client_Socket;
use OCA\Mail\Account;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\BodyCacheService;
use OCP\AppFramework\Db\DoesNotExistException;
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
	private function fetchForAccount(string $userId, Account $account, Horde_Imap_Client_Socket $client, array $rows, int $deadline): void {
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
				$result = $this->cache->put($userId, $account->getId(), $message, $imapMessage);
				if ($result === BodyCacheService::PUT_FULL) {
					$this->logger->debug('Body prefetch stopped account {accountId}: size limit reached', [
						'accountId' => $account->getId(),
					]);
					return;
				}
				if ($result === BodyCacheService::PUT_EXCLUDED) {
					$this->cache->markFailed($userId, $account->getId(), $message);
				}
			} catch (Throwable $e) {
				if ($this->stopsAccount($e)) {
					$this->logger->info('Body prefetch stopped account {accountId}: {message}', [
						'accountId' => $account->getId(),
						'message' => $e->getMessage(),
					]);
					return;
				}
				// Only a message that is gone is remembered. Anything else is
				// tried again on the next run, with no failure row.
				if ($this->messageIsGone($e)) {
					$this->failed($userId, $account, $row, $e);
				} else {
					$this->logger->debug('Body prefetch left message {id} for the next run: {message}', [
						'id' => $row['id'],
						'message' => $e->getMessage(),
					]);
				}
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

	/**
	 * Authentication failure and a dropped connection end this account's run
	 * without a failure row. The connection codes are the same ones
	 * ErrorMiddleware treats as temporary.
	 */
	private function stopsAccount(Throwable $e): bool {
		if ($e instanceof ServiceException && $e->getPrevious() !== null) {
			$e = $e->getPrevious();
		}
		if (!$e instanceof Horde_Imap_Client_Exception) {
			return false;
		}
		return in_array($e->getCode(), [
			Horde_Imap_Client_Exception::LOGIN_AUTHENTICATIONFAILED,
			Horde_Imap_Client_Exception::DISCONNECT,
			Horde_Imap_Client_Exception::SERVER_READERROR,
			Horde_Imap_Client_Exception::SERVER_WRITEERROR,
		], true);
	}

	/**
	 * getImapMessage wraps a missing message in ServiceException. That is the
	 * only failure kept, so the job does not fetch it on every run.
	 */
	private function messageIsGone(Throwable $e): bool {
		return $e instanceof ServiceException
			&& $e->getPrevious() instanceof DoesNotExistException;
	}
}
