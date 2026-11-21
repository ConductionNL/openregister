<?php

/**
 * DeletedFilter — what a read of the deleted list is narrowed to (incremental-sync-for-api-consumers D-2).
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * A register, a schema and a deletion moment the deleted list is narrowed to.
 *
 * The register and schema pick the magic tables to scan; the moment keeps the
 * rows whose deletion is at or after it. The moment is compared after parsing
 * each row's marker, not as text in SQL: `deletedAt` is stored as ISO 8601
 * with the writer's offset, and a row written before the marker was JSON holds
 * a bare timestamp, so a text comparison would order some rows wrongly.
 *
 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-the-deleted-list-filters-by-register-schema-and-deletion-time-req-sync-002
 */
final class DeletedFilter {

	/**
	 * Constructor.
	 *
	 * @param int|null               $registerId   Only this register's tables, or every register.
	 * @param int|null               $schemaId     Only this schema's tables, or every schema.
	 * @param DateTimeImmutable|null $deletedSince Only rows deleted at or after this moment, or every row.
	 */
	public function __construct(
		public readonly ?int $registerId=null,
		public readonly ?int $schemaId=null,
		public readonly ?DateTimeImmutable $deletedSince=null,
	) {
	}//end __construct()

	/**
	 * Whether a magic table is in scope.
	 *
	 * @param array{registerId: int, schemaId: int} $table The table's register and schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-the-deleted-list-filters-by-register-schema-and-deletion-time-req-sync-002
	 */
	public function admitsTable(array $table): bool {
		if ($this->registerId !== null && (int)$table['registerId'] !== $this->registerId) {
			return false;
		}

		return $this->schemaId === null || (int)$table['schemaId'] === $this->schemaId;
	}//end admitsTable()

	/**
	 * Whether a row's `_deleted` marker is at or after the moment.
	 *
	 * A marker whose deletion moment cannot be read is not admitted when a
	 * moment is asked for: it cannot be shown to be inside the window.
	 *
	 * @param mixed $marker The raw `_deleted` column value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-the-deleted-list-filters-by-register-schema-and-deletion-time-req-sync-002
	 */
	public function admitsMarker(mixed $marker): bool {
		if ($this->deletedSince === null) {
			return true;
		}

		$deletedAt = self::deletedAt(marker: $marker);

		return $deletedAt !== null && $deletedAt >= $this->deletedSince;
	}//end admitsMarker()

	/**
	 * Whether the rows themselves must be read to apply the filter.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-the-deleted-list-filters-by-register-schema-and-deletion-time-req-sync-002
	 */
	public function readsMarkers(): bool {
		return $this->deletedSince !== null;
	}//end readsMarkers()

	/**
	 * The deletion moment a marker records, or null when it records none.
	 *
	 * @param mixed $marker The JSON marker (`deletedAt`, or the older `deleted`), or a bare timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function deletedAt(mixed $marker): ?DateTimeImmutable {
		$decoded = $marker;
		if (is_string($marker) === true) {
			$decoded = json_decode($marker, true);
			if (is_array($decoded) === false) {
				$decoded = ['deletedAt' => $marker];
			}
		}

		if (is_array($decoded) === false) {
			return null;
		}

		$value = ($decoded['deletedAt'] ?? $decoded['deleted'] ?? null);
		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception $e) {
			return null;
		}
	}//end deletedAt()
}//end class
