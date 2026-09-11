<?php

/**
 * Where a retention decision can be stored, and how to walk all of it.
 *
 * 🔴 THIS EXISTS BECAUSE EVERY RETENTION SWEEP READ THE WRONG TABLE.
 * OpenRegister keeps objects in per-schema magic tables
 * (`openregister_table_<registerId>_<schemaId>`, each with its own
 * `_retention` column). `BlobMigrationJob` drains the legacy blob table
 * `openregister_objects` into them every five minutes and deletes the
 * originals. Three separate sweeps still selected from that legacy table, so
 * on a migrated install each one scanned zero rows and reported "nothing
 * eligible". Measured on the shared development database: `openregister_objects`
 * held 0 rows against 1320 magic tables, all 1320 of them carrying `_retention`.
 *
 * One scanner, so the next sweep that needs this inherits the right answer
 * instead of writing a fourth query against whichever table it heard of first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Pages every table a retention decision can live in and hands back the matches.
 */
class RetentionRowScanner {

	/**
	 * The most matches one retention sweep will collect before it stops.
	 *
	 * A first run on an install that has never swept can face years of backlog.
	 * Handing all of it to `createDestructionList()` in one go produces a list
	 * nobody can review and, on the notification path, a mail storm. The sweeps
	 * are idempotent and run daily, so stopping at a bounded number costs a day
	 * per batch and nothing else.
	 *
	 * A TRUNCATED RUN SAYS SO, AT WARNING LEVEL. A sweep that quietly stops
	 * early is the same class of defect this cap sits inside: a job that
	 * reports success while having looked at part of the data.
	 */
	public const MAX_MATCHES_PER_RUN = 500;

	/**
	 * Rows read per page while scanning one retention source.
	 *
	 * Bounds peak memory: a register with a million rows is read a page at a
	 * time rather than fetched whole.
	 */
	private const SCAN_BATCH_SIZE = 500;

	/**
	 * The legacy blob table objects were stored in before magic tables.
	 */
	private const LEGACY_TABLE = 'openregister_objects';

	/**
	 * The magic tables' own id column.
	 *
	 * Magic tables prefix every metadata column with an underscore so a
	 * schema-declared property can never collide with one. `MagicMapper` keeps
	 * that prefix private, so the two columns this sweep needs are named here.
	 */
	private const MAGIC_ID_COLUMN = '_id';

	/**
	 * The magic tables' own retention column.
	 */
	private const MAGIC_RETENTION_COLUMN = '_retention';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db             Database connection for the row scans.
	 * @param RegisterMapper  $registerMapper Register repository, used to enumerate pairs.
	 * @param SchemaMapper    $schemaMapper   Schema repository, used to enumerate pairs.
	 * @param MagicMapper     $objectMapper   Magic-table resolver and row hydrator.
	 * @param LoggerInterface $logger         Logger for skipped sources and truncated runs.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Visit every object carrying a retention decision, wherever it lives.
	 *
	 * 🔴 THIS READS BOTH STORES, BECAUSE READING ONE OF THEM FINDS NOTHING.
	 * OpenRegister keeps objects in per-schema magic tables
	 * (`openregister_table_<registerId>_<schemaId>`, each with its own
	 * `_retention` column). `BlobMigrationJob` drains the legacy blob table
	 * `openregister_objects` into them every five minutes and deletes the
	 * originals. Every retention sweep in this app still selected from that
	 * legacy table, so on a migrated install each one scanned zero rows and
	 * reported "nothing eligible". Measured on the shared development database:
	 * `openregister_objects` held 0 rows against 1320 magic tables, all 1320 of
	 * them carrying `_retention`.
	 *
	 * So both sources are scanned, not one. An install that has finished the
	 * blob migration reads its magic tables; an install that has not started it
	 * reads the blob table; an install part-way through reads both and the
	 * duplicate-free answer falls out of the migration deleting what it moves.
	 *
	 * NO JSON FILTERING IN SQL. OpenRegister runs on PostgreSQL and on
	 * MySQL/MariaDB, whose JSON path syntax differs, so the SQL asks only
	 * "is there a retention decision at all" and eligibility is decided in PHP
	 * on the hydrated object. That is the property the previous implementation
	 * had and the one thing about it that was right.
	 *
	 * @param callable $accept  Predicate answering whether one object is a match.
	 * @param int|null $maxMatches Cap for this run; defaults to MAX_MATCHES_PER_RUN.
	 *
	 * @return array{objects: ObjectEntity[], scanned: int, truncated: bool} The matches,
	 *               how many rows were inspected, and whether the cap stopped the run.
	 *
	 * @psalm-param callable(ObjectEntity): bool $accept
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 */
	public function scan(callable $accept, ?int $maxMatches = null): array {
		$cap = ($maxMatches ?? self::MAX_MATCHES_PER_RUN);
		$objects = [];
		$scanned = 0;
		$truncated = false;

		foreach ($this->retentionSources() as $source) {
			$outcome = $this->scanRetentionSource(
				source: $source,
				accept: $accept,
				remaining: ($cap - count($objects))
			);

			$scanned += $outcome['scanned'];
			foreach ($outcome['objects'] as $object) {
				$objects[] = $object;
			}

			if ($outcome['truncated'] === true) {
				$truncated = true;
				break;
			}
		}

		if ($truncated === true) {
			// A SILENTLY TRUNCATED SWEEP IS THE DEFECT THIS METHOD EXISTS TO
			// FIX, ONE LEVEL UP. Saying so out loud is the difference between a
			// backlog being worked through a batch at a time and a records
			// officer believing the whole estate was examined.
			$this->logger->warning(
				sprintf(
					'[RetentionRowScanner] Retention scan stopped at its cap: %d matches found, cap is %d '
					. 'per run, %d rows inspected. The remainder is picked up by the next run.',
					count($objects),
					$cap,
					$scanned
				),
				[
					'app' => 'openregister',
					'matched' => count($objects),
					'cap' => $cap,
					'scanned' => $scanned,
					'truncated' => true,
				]
			);
		}

		return [
			'objects' => $objects,
			'scanned' => $scanned,
			'truncated' => $truncated,
		];
	}//end scan()

	/**
	 * Every table a retention decision can be stored in, in scan order.
	 *
	 * The legacy blob table goes first: it is a single table, it is empty on a
	 * migrated install so it costs one query, and on an install that never
	 * started the blob migration it is the only place anything lives.
	 *
	 * @return array<int, array<string, mixed>> Source descriptors.
	 */
	private function retentionSources(): array {
		$sources = [
			[
				'table' => self::LEGACY_TABLE,
				'label' => self::LEGACY_TABLE,
				'select' => '*',
				'idColumn' => 'id',
				'retentionColumn' => 'retention',
				'legacy' => true,
				'register' => null,
				'schema' => null,
			],
		];

		foreach ($this->magicTableSources() as $source) {
			$sources[] = $source;
		}

		return $sources;
	}//end retentionSources()

	/**
	 * Resolve one source per (register, schema) pair that has a magic table.
	 *
	 * A pair whose table has not been materialised is SKIPPED, not thrown on: a
	 * schema with no rows yet is an ordinary state, and one unmaterialised pair
	 * must not take down the sweep for every other pair in the install.
	 *
	 * @return array<int, array<string, mixed>> Source descriptors, possibly empty.
	 */
	private function magicTableSources(): array {
		try {
			$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionRowScanner] Could not enumerate registers for the retention scan: ' . $e->getMessage(),
				['app' => 'openregister', 'exception' => $e]
			);
			return [];
		}

		$sources = [];
		$seen = [];

		foreach ($registers as $register) {
			if (($register instanceof Register) === false) {
				continue;
			}

			foreach (($register->getSchemas() ?? []) as $schemaId) {
				$source = $this->magicTableSource(register: $register, schemaId: $schemaId);
				if ($source === null) {
					continue;
				}

				if (isset($seen[$source['table']]) === true) {
					continue;
				}

				$seen[$source['table']] = true;
				$sources[] = $source;
			}
		}

		return $sources;
	}//end magicTableSources()

	/**
	 * One magic-table source descriptor, or null when the pair has no table.
	 *
	 * @param Register   $register The register the pair belongs to.
	 * @param string|int $schemaId The schema id as the register lists it.
	 *
	 * @return array<string, mixed>|null The descriptor, or null when unusable.
	 */
	private function magicTableSource(Register $register, string|int $schemaId): ?array {
		try {
			$schema = $this->schemaMapper->find((int)$schemaId);

			if ($this->objectMapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
				return null;
			}

			$table = $this->objectMapper->getTableNameForRegisterSchema(register: $register, schema: $schema);
		} catch (Exception $e) {
			$this->logger->warning(
				sprintf(
					'[RetentionRowScanner] Skipping register #%s schema #%s in the retention scan: %s',
					(string)$register->getId(),
					(string)$schemaId,
					$e->getMessage()
				),
				['app' => 'openregister']
			);
			return null;
		}//end try

		return [
			'table' => $table,
			'label' => $table,
			'select' => self::MAGIC_ID_COLUMN,
			'idColumn' => self::MAGIC_ID_COLUMN,
			'retentionColumn' => self::MAGIC_RETENTION_COLUMN,
			'legacy' => false,
			'register' => $register,
			'schema' => $schema,
		];
	}//end magicTableSource()

	/**
	 * Page one source, hydrate its retention rows and keep the matches.
	 *
	 * @param array<string, mixed> $source    The source descriptor.
	 * @param callable             $accept    Predicate answering whether one object is a match.
	 * @param int                  $remaining How many more matches this run may still collect.
	 *
	 * @return array{objects: ObjectEntity[], scanned: int, truncated: bool} What this source contributed.
	 *
	 * @psalm-param callable(ObjectEntity): bool $accept
	 */
	private function scanRetentionSource(array $source, callable $accept, int $remaining): array {
		$objects = [];
		$scanned = 0;

		if ($remaining <= 0) {
			return ['objects' => $objects, 'scanned' => $scanned, 'truncated' => true];
		}

		$offset = 0;

		try {
			do {
				$qb = $this->db->getQueryBuilder();
				$qb->select($source['select'])
					->from($source['table'])
					->where($qb->expr()->isNotNull($source['retentionColumn']))
					->setFirstResult($offset)
					->setMaxResults(self::SCAN_BATCH_SIZE);

				$result = $qb->executeQuery();
				$rows = $result->fetchAll();
				$result->closeCursor();

				foreach ($rows as $row) {
					$scanned++;

					$object = $this->hydrateRetentionRow(source: $source, row: $row);
					if ($object === null) {
						continue;
					}

					if ($accept($object) === false) {
						continue;
					}

					$objects[] = $object;

					if (count($objects) >= $remaining) {
						return ['objects' => $objects, 'scanned' => $scanned, 'truncated' => true];
					}
				}

				$rowCount = count($rows);
				$offset += self::SCAN_BATCH_SIZE;
			} while ($rowCount === self::SCAN_BATCH_SIZE);
		} catch (Exception $e) {
			// One unreadable table must not silence every other one. The blob
			// table is absent on installs created after it was retired, and a
			// magic table can be dropped between the existence check and the
			// read.
			$this->logger->warning(
				sprintf(
					'[RetentionRowScanner] Retention scan skipped source "%s": %s',
					(string)$source['label'],
					$e->getMessage()
				),
				['app' => 'openregister']
			);
		}//end try

		return ['objects' => $objects, 'scanned' => $scanned, 'truncated' => false];
	}//end scanRetentionSource()

	/**
	 * Turn one scanned row into an object, or null when it cannot be read.
	 *
	 * 🔴 THE LEGACY ROW IS HYDRATED IN PLACE, NOT LOOKED UP.
	 * `MagicMapper::find()` without a register and schema searches the MAGIC
	 * tables only, so feeding it an id read out of the blob table looks the id
	 * up in a different id space entirely — it finds the wrong object or none
	 * at all. The row is already in hand; hydrate that.
	 *
	 * @param array<string, mixed> $source The source descriptor.
	 * @param array<string, mixed> $row    The scanned row.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be loaded.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `Entity::fromRow()` is Nextcloud's own
	 *              row constructor, not a collaborator that could be injected.
	 */
	private function hydrateRetentionRow(array $source, array $row): ?ObjectEntity {
		try {
			if ($source['legacy'] === true) {
				return ObjectEntity::fromRow($row);
			}

			return $this->objectMapper->find(
				$row[$source['idColumn']],
				$source['register'],
				$source['schema'],
				false,
				false,
				false
			);
		} catch (Exception $e) {
			return null;
		}
	}//end hydrateRetentionRow()
}//end class
