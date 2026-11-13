<?php

/**
 * The scheduled messages table, read and written with a compare-and-set claim.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * `openregister_scheduled_messages` behind the sweep.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */
class ScheduledMessageStore implements ScheduledMessageStoreInterface {

	/**
	 * The table.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_scheduled_messages';

	/**
	 * Row field => column, for every field the sweep writes.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = [
		'state' => 'state',
		'attempts' => 'attempts',
		'claimedBy' => 'claimed_by',
		'claimedAt' => 'claimed_at',
		'lastError' => 'last_error',
		'messageId' => 'message_id',
		'response' => 'response',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The pending and claimed rows, oldest first.
	 *
	 * @param int $limit The most rows to return.
	 *
	 * @return array<int, array<string, mixed>> The rows, in the policy's shape.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function open(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$open = [ScheduledMessagePolicy::PENDING, ScheduledMessagePolicy::CLAIMED];
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->in('state', $qb->createNamedParameter($open, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows   = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = self::rowOf(row: $row);
		}

		$result->closeCursor();

		return $rows;
	}//end open()

	/**
	 * Write the claim only when id, state and attempts still match.
	 *
	 * @param array{id:string,state:string,attempts:int} $expect What the row must still hold.
	 * @param array<string, mixed>                        $set    The fields to write.
	 *
	 * @return bool True when exactly this call changed the row.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function compareAndSet(array $expect, array $set): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE);
		$this->setFields(qb: $qb, fields: $set);
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$expect['id'], IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter((string)$expect['state'])))
			->andWhere($qb->expr()->eq('attempts', $qb->createNamedParameter((int)$expect['attempts'], IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() === 1;
	}//end compareAndSet()

	/**
	 * Write the outcome of a send.
	 *
	 * @param string               $id     The row id.
	 * @param array<string, mixed> $fields The fields to write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function record(string $id, array $fields): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE);
		$this->setFields(qb: $qb, fields: $fields);
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end record()

	/**
	 * Set the known fields, and `updated`, on an update.
	 *
	 * @param IQueryBuilder        $qb     The update.
	 * @param array<string, mixed> $fields Row field => value.
	 *
	 * @return void
	 */
	private function setFields(IQueryBuilder $qb, array $fields): void {
		foreach ($fields as $field => $value) {
			$column = (self::COLUMNS[$field] ?? null);
			if ($column === null) {
				continue;
			}

			if (is_array($value) === true) {
				$value = json_encode($value);
			}

			$qb->set($column, $qb->createNamedParameter($value));
		}

		$qb->set('updated', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')));
	}//end setFields()

	/**
	 * One stored row in the shape the policy reads.
	 *
	 * @param array<string, mixed> $row The database row.
	 *
	 * @return array<string, mixed>
	 */
	private static function rowOf(array $row): array {
		return [
			'id' => (string)($row['id'] ?? ''),
			'state' => (string)($row['state'] ?? ScheduledMessagePolicy::PENDING),
			'attempts' => (int)($row['attempts'] ?? 0),
			'sendAt' => (string)($row['send_at'] ?? ''),
			'claimedAt' => (string)($row['claimed_at'] ?? ''),
			'source' => (string)($row['source'] ?? ''),
			'path' => (string)($row['path'] ?? ''),
			'body' => self::decoded(json: $row['body'] ?? null),
			'headers' => self::decoded(json: $row['headers'] ?? null),
			'messageId' => (string)($row['message_id'] ?? ''),
		];
	}//end rowOf()

	/**
	 * A JSON column as an array; anything else as empty.
	 *
	 * @param mixed $json The column value.
	 *
	 * @return array<mixed>
	 */
	private static function decoded(mixed $json): array {
		if (is_string($json) === false || $json === '') {
			return [];
		}

		$value = json_decode($json, true);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end decoded()
}//end class
