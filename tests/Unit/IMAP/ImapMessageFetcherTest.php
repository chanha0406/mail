<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 YouCha
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\IMAP;

use Horde_Imap_Client;
use Horde_Imap_Client_Data_Fetch;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Fetch_Results;
use Horde_Imap_Client_Socket;
use OCA\Mail\IMAP\ImapMessageFetcher;
use PHPUnit\Framework\TestCase;

class ImapMessageFetcherTest extends TestCase {
	public function testFetchBodyPartsReturnsTheFirstHit(): void {
		$uid = 7;
		$fetchData = new Horde_Imap_Client_Data_Fetch();
		$fetchData->setUid($uid);
		$fetchData->setBodyPart('1', 'body');
		$hit = new Horde_Imap_Client_Fetch_Results();
		$hit[$uid] = $fetchData;
		$client = $this->clientReturning([$hit]);

		$result = ImapMessageFetcher::fetchBodyParts($client, 'INBOX', $uid, ['1']);

		$this->assertSame($fetchData, $result);
		$this->assertCount(1, $client->queries);
		$this->assertTrue($client->queries[0]->contains(Horde_Imap_Client::FETCH_MIMEHEADER));
	}

	public function testFetchBodyPartsRetriesWithoutMimeHeader(): void {
		$uid = 7;
		$fetchData = new Horde_Imap_Client_Data_Fetch();
		$fetchData->setUid($uid);
		$fetchData->setBodyPart('1', 'body');
		$fetchData->setBodyPart('2', 'html');
		$hit = new Horde_Imap_Client_Fetch_Results();
		$hit[$uid] = $fetchData;
		$calls = 0;
		$client = $this->clientReturning([new Horde_Imap_Client_Fetch_Results(), $hit]);

		$result = ImapMessageFetcher::fetchBodyParts(
			$client,
			'INBOX',
			$uid,
			['1', '2'],
			function (Horde_Imap_Client_Fetch_Query $query) use (&$calls): void {
				$calls++;
				$query->headerText(['peek' => true]);
			},
		);

		$this->assertSame($fetchData, $result);
		$this->assertSame(2, $calls);
		$this->assertTrue($client->queries[0]->contains(Horde_Imap_Client::FETCH_MIMEHEADER));
		$this->assertFalse($client->queries[1]->contains(Horde_Imap_Client::FETCH_MIMEHEADER));
		$this->assertTrue($client->queries[1]->contains(Horde_Imap_Client::FETCH_BODYPART));
		$this->assertTrue($client->queries[1]->contains(Horde_Imap_Client::FETCH_HEADERTEXT));
		$parts = $client->queries[1][Horde_Imap_Client::FETCH_BODYPART];
		$this->assertArrayHasKey('1', $parts);
		$this->assertArrayHasKey('2', $parts);
	}

	public function testFetchBodyPartsOfMissingMessageFetchesTwice(): void {
		$client = $this->clientReturning([
			new Horde_Imap_Client_Fetch_Results(),
			new Horde_Imap_Client_Fetch_Results(),
		]);

		$result = ImapMessageFetcher::fetchBodyParts($client, 'INBOX', 7, ['1']);

		$this->assertNull($result);
		$this->assertCount(2, $client->queries);
		$this->assertFalse($client->queries[1]->contains(Horde_Imap_Client::FETCH_MIMEHEADER));
	}

	/**
	 * @param list<Horde_Imap_Client_Fetch_Results> $results
	 */
	private function clientReturning(array $results): Horde_Imap_Client_Socket {
		return new class(['username' => 'alice'], $results) extends Horde_Imap_Client_Socket {
			/** @var list<Horde_Imap_Client_Fetch_Query> */
			public array $queries = [];

			/**
			 * @param list<Horde_Imap_Client_Fetch_Results> $results
			 */
			public function __construct(array $params, private array $results) {
				parent::__construct($params);
			}

			public function fetch($mailbox, $query, array $options = []) {
				$this->queries[] = $query;
				return array_shift($this->results);
			}
		};
	}
}
