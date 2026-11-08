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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
	 * The legacy blob table as a source descriptor.
	 */
	private const LEGACY_SOURCE = [
		'table' => self::LEGACY_TABLE,
		'label' => self::LEGACY_TABLE,
		'select' => '*',
		'idColumn' => 'id',
		'retentionColumn' => 'retention',
		'legacy' => true,
		'register' => null,
		'schema' => null,
	];

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
	 * @param RegisterMapperInterface  $registerMapper Register repository, used to enumerate pairs.
	 * @param SchemaMapperInterface    $schemaMapper   Schema repository, used to enumerate pairs.
	 * @param MagicMapperInterface     $objectMapper   Magic-table resolver and row hydrator.
	 * @param LoggerInterface $logger         Logger for skipped sources and truncated runs.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly MagicMapperInterface $objectMapper,
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
		$run = ['objects' => [], 'scanned' => 0, 'truncated' => false];

		foreach ($this->retentionSources() as $source) {
			$outcome = $this->scanRetentionSource(source: $source, accept: $accept, remaining: ($cap - count($run['objects'])));

			$run['scanned'] += $outcome['scanned'];
			array_push($run['objects'], ...$outcome['objects']);

			if ($outcome['truncated'] === true) {
				$run['truncated'] = true;
				$this->warnTruncated(run: $run, cap: $cap);
				break;
			}
		}

		return $run;
	}//end scan()

	/**
	 * Say, at warning level, that a run stopped at its cap.
	 *
	 * A SILENTLY TRUNCATED SWEEP IS THE DEFECT THIS METHOD EXISTS TO FIX, ONE
	 * LEVEL UP. Saying so out loud is the difference between a backlog being
	 * worked through a batch at a time and a records officer believing the
	 * whole estate was examined.
	 *
	 * @param array{objects: ObjectEntity[], scanned: int, truncated: bool} $run The run so far.
	 * @param int                                                          $cap The cap for this run.
	 *
	 * @return void
	 */
	private function warnTruncated(array $run, int $cap): void {
		$matched = count($run['objects']);
		$this->logger->warning(
			sprintf(
				'[RetentionRowScanner] Retention scan stopped at its cap: %d matches found, cap is %d '
				. 'per run, %d rows inspected. The remainder is picked up by the next run.',
				$matched,
				$cap,
				$run['scanned']
			),
			['app' => 'openregister', 'matched' => $matched, 'cap' => $cap, 'scanned' => $run['scanned'], 'truncated' => true]
		);
	}//end warnTruncated()

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
		return array_merge([self::LEGACY_SOURCE], $this->magicTableSources());
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
		$sources = [];
		foreach ($this->registers() as $register) {
			if (($register instanceof Register) === false) {
				continue;
			}

			// The first pair naming a table is the one scanned.
			foreach ($this->sourcesOfRegister(register: $register) as $source) {
				$sources[$source['table']] ??= $source;
			}
		}

		return array_values($sources);
	}//end magicTableSources()

	/**
	 * Every register, unscoped; none when they cannot be enumerated.
	 *
	 * @return array<int, mixed> The registers.
	 */
	private function registers(): array {
		try {
			return $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Exception $e) {
			$this->logger->warning(
				'[RetentionRowScanner] Could not enumerate registers for the retention scan: ' . $e->getMessage(),
				['app' => 'openregister', 'exception' => $e]
			);
			return [];
		}
	}//end registers()

	/**
	 * The magic-table sources of one register's schemas, in the register's order.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<int, array<string, mixed>> Source descriptors.
	 */
	private function sourcesOfRegister(Register $register): array {
		$sources = [];
		foreach (($register->getSchemas() ?? []) as $schemaId) {
			$source = $this->magicTableSource(register: $register, schemaId: $schemaId);
			if ($source !== null) {
				$sources[] = $source;
			}
		}

		return $sources;
	}//end sourcesOfRegister()

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
			$this->skipPair(register: $register, schemaId: $schemaId, error: $e);
			return null;
		}

		return $this->magicDescriptor(table: $table, register: $register, schema: $schema);
	}//end magicTableSource()

	/**
	 * Say that one (register, schema) pair is left out of the scan.
	 *
	 * @param Register   $register The register the pair belongs to.
	 * @param string|int $schemaId The schema id as the register lists it.
	 * @param Exception  $error    Why the pair could not be resolved.
	 *
	 * @return void
	 */
	private function skipPair(Register $register, string|int $schemaId, Exception $error): void {
		$this->logger->warning(
			sprintf(
				'[RetentionRowScanner] Skipping register #%s schema #%s in the retention scan: %s',
				(string)$register->getId(),
				(string)$schemaId,
				$error->getMessage()
			),
			['app' => 'openregister']
		);
	}//end skipPair()

	/**
	 * A magic table as a source descriptor.
	 *
	 * @param string   $table    The table name.
	 * @param Register $register The register the pair belongs to.
	 * @param \OCA\OpenRegister\Db\Schema $schema The schema the pair belongs to.
	 *
	 * @return array<string, mixed> The descriptor.
	 */
	private function magicDescriptor(string $table, Register $register, \OCA\OpenRegister\Db\Schema $schema): array {
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
	}//end magicDescriptor()

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
		$outcome = ['objects' => [], 'scanned' => 0, 'truncated' => ($remaining <= 0)];
		if ($outcome['truncated'] === true) {
			return $outcome;
		}

		try {
			$truncated = $this->pageThrough(source: $source, accept: $accept, remaining: $remaining, outcome: $outcome);
			$outcome['truncated'] = $truncated;
		} catch (Exception $e) {
			// One unreadable table must not silence every other one. The blob
			// table is absent on installs created after it was retired, and a
			// magic table can be dropped between the existence check and the
			// read. What was collected before the failure is kept.
			$message = sprintf('[RetentionRowScanner] Retention scan skipped source "%s": %s', (string)$source['label'], $e->getMessage());
			$this->logger->warning($message, ['app' => 'openregister']);
		}

		return $outcome;
	}//end scanRetentionSource()

	/**
	 * Read one source a page at a time into the outcome, until it runs out or the cap is reached.
	 *
	 * @param array<string, mixed> $source    The source descriptor.
	 * @param callable             $accept    Predicate answering whether one object is a match.
	 * @param int                  $remaining How many more matches this run may still collect.
	 * @param array<string, mixed> $outcome   The outcome so far, added to in place.
	 *
	 * @return bool True when the cap was reached.
	 *
	 * @throws Exception When the source cannot be read.
	 */
	private function pageThrough(array $source, callable $accept, int $remaining, array &$outcome): bool {
		$offset = 0;
		do {
			$rows = $this->page(source: $source, offset: $offset);
			if ($this->collect(source: $source, rows: $rows, accept: $accept, remaining: $remaining, outcome: $outcome) === true) {
				return true;
			}

			$offset += self::SCAN_BATCH_SIZE;
		} while (count($rows) === self::SCAN_BATCH_SIZE);

		return false;
	}//end pageThrough()

	/**
	 * One page of retention rows from a source.
	 *
	 * @param array<string, mixed> $source The source descriptor.
	 * @param int                  $offset The first row of the page.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws Exception When the source cannot be read.
	 */
	private function page(array $source, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($source['select'])
			->from($source['table'])
			->where($qb->expr()->isNotNull($source['retentionColumn']))
			->setFirstResult($offset)
			->setMaxResults(self::SCAN_BATCH_SIZE);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end page()

	/**
	 * Count every row of a page as inspected and keep its matches, stopping at the cap.
	 *
	 * @param array<string, mixed>             $source    The source descriptor.
	 * @param array<int, array<string, mixed>> $rows      The page.
	 * @param callable                         $accept    Predicate answering whether one object is a match.
	 * @param int                              $remaining How many more matches this run may still collect.
	 * @param array<string, mixed>             $outcome   The outcome so far, added to in place.
	 *
	 * @return bool True when the cap was reached.
	 */
	private function collect(array $source, array $rows, callable $accept, int $remaining, array &$outcome): bool {
		foreach ($rows as $row) {
			$outcome['scanned']++;

			$object = $this->hydrateRetentionRow(source: $source, row: $row);
			if ($object === null || $accept($object) === false) {
				continue;
			}

			$outcome['objects'][] = $object;
			if (count($outcome['objects']) >= $remaining) {
				return true;
			}
		}

		return false;
	}//end collect()

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

			return $this->objectMapper->find($row[$source['idColumn']], $source['register'], $source['schema'], false, false, false);
		} catch (Exception $e) {
			return null;
		}
	}//end hydrateRetentionRow()
}//end class
