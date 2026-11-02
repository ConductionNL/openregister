<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reads and writes rows of `openregister_file_restrictions`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTimeImmutable;
use OCA\OpenRegister\Migration\Version1Date20261024200000;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The per-file restriction rows, as plain arrays.
 *
 * A row is `{fileId, users, groups, reason, setBy, setAt}`; `setAt` is ISO 8601.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */
class FileRestrictionStore {

	/**
	 * How many file ids one IN() list carries.
	 */
	private const CHUNK = 500;

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
	 * The restriction of one file, or null when it has none.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array{fileId: int, users: list<string>, groups: list<string>, reason: string, setBy: string, setAt: string}|null The row.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function find(int $fileId): ?array {
		return ($this->findMany(fileIds: [$fileId])[$fileId] ?? null);
	}//end find()

	/**
	 * The restrictions of several files, keyed by file id; a file without one is absent.
	 *
	 * @param list<int> $fileIds The Nextcloud file ids.
	 *
	 * @return array<int, array{fileId: int, users: list<string>, groups: list<string>, reason: string, setBy: string, setAt: string}> The rows.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function findMany(array $fileIds): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0)));
		$rows = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id', 'user_ids', 'group_ids', 'reason', 'set_by', 'set_at')
				->from(Version1Date20261024200000::TABLE)
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$fileId = (int)$row['file_id'];
				$rows[$fileId] = [
					'fileId' => $fileId,
					'users' => $this->decodeList(value: $row['user_ids'] ?? null),
					'groups' => $this->decodeList(value: $row['group_ids'] ?? null),
					'reason' => (string)($row['reason'] ?? ''),
					'setBy' => (string)($row['set_by'] ?? ''),
					'setAt' => $this->isoDate(value: $row['set_at'] ?? null),
				];
			}

			$result->closeCursor();
		}

		return $rows;
	}//end findMany()

	/**
	 * Store the restriction of one file, replacing the one it had.
	 *
	 * @param int               $fileId The Nextcloud file id.
	 * @param list<string>      $users  The users.
	 * @param list<string>      $groups The groups.
	 * @param string            $reason Why.
	 * @param string            $setBy  Who set it.
	 * @param DateTimeImmutable $setAt  When.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function save(int $fileId, array $users, array $groups, string $reason, string $setBy, DateTimeImmutable $setAt): void {
		$values = [
			'user_ids' => json_encode(array_values($users)),
			'group_ids' => json_encode(array_values($groups)),
			'reason' => $reason,
			'set_by' => $setBy,
			'set_at' => $setAt->format('Y-m-d H:i:s'),
		];

		$update = $this->db->getQueryBuilder();
		$update->update(Version1Date20261024200000::TABLE);
		foreach ($values as $column => $value) {
			$update->set($column, $update->createNamedParameter($value));
		}

		$update->where($update->expr()->eq('file_id', $update->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if ($update->executeStatement() > 0) {
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert(Version1Date20261024200000::TABLE)
			->setValue('file_id', $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT));
		foreach ($values as $column => $value) {
			$insert->setValue($column, $insert->createNamedParameter($value));
		}

		$insert->executeStatement();
	}//end save()

	/**
	 * Remove the restriction of one file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function delete(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(Version1Date20261024200000::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end delete()

	/**
	 * A stored JSON list as a list of non-empty strings.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return list<string> The list.
	 */
	private function decodeList(mixed $value): array {
		if (is_string($value) === false || $value === '') {
			return [];
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $decoded), static fn (string $item): bool => $item !== ''));
	}//end decodeList()

	/**
	 * A stored datetime as ISO 8601, or '' when there is none.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return string The date.
	 */
	private function isoDate(mixed $value): string {
		if (is_string($value) === false || $value === '') {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
		if ($date === false) {
			return $value;
		}

		return $date->format(DATE_ATOM);
	}//end isoDate()
}//end class
