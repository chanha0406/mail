<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 YouCha
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Prefetched message bodies (self-hosted fork). One row per mail_messages row.
 */
class Version5990Date20261021000000 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('mail_body_cache')) {
			return null;
		}

		$table = $schema->createTable('mail_body_cache');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('message_id', Types::BIGINT, [
			'notnull' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('account_id', Types::INTEGER, [
			'notnull' => true,
		]);
		$table->addColumn('sent_at', Types::INTEGER, [
			'notnull' => true,
		]);
		$table->addColumn('size', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('created_at', Types::INTEGER, [
			'notnull' => true,
		]);
		// gzip-compressed JSON; null marks a message that could not be prefetched
		$table->addColumn('data', Types::BLOB, [
			'notnull' => false,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['message_id'], 'mail_body_cache_msg_uidx');
		$table->addIndex(['user_id', 'sent_at'], 'mail_body_cache_user_idx');

		return $schema;
	}
}
