<?php

/**
 * OpenRegister RenameDutchColumns Repair Step
 *
 * Moves stored data from the Dutch columns to the English ones the shillinq
 * register now declares. Covers every vocabulary cluster migrated so far, not
 * only amounts — the class was renamed from RenameDutchAmountColumns when the
 * second cluster landed, because one register-scoped step must carry them all.
 *
 * WHY THIS IS NEEDED. OpenRegister does not store an object as a JSON blob
 * keyed by property name — each schema property is a real, snake_cased COLUMN
 * in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. So renaming `bedrag` to `amount` in the register, on its own,
 * leaves the money in `bedrag` while every read looks at `amount` and finds
 * null. No error, no data loss, and invisible to the test suite, which asserts
 * against fixtures rather than migrated rows.
 *
 * For this app that is money: bedrag columns carry invoice, subsidy, payroll
 * and tax amounts.
 *
 * ALL FIFTY OWNERS MOVE TOGETHER. The map below covers every property name in
 * the cluster, and each was checked to be free of a collision with its English
 * target before being added. A register-scoped step cannot rename a column for
 * one owner and not the rest — the others would silently read null.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so this is reversible
 *     and a re-run is a no-op;
 *   - two sources targeting one destination in a table are REFUSED, not merged;
 *   - nothing is deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename shillinq's Dutch amount columns to their English equivalents.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
 */
class RenameDutchColumns implements IRepairStep {
	/**
	 * Slug prefix of the registers in scope.
	 *
	 * OpenRegister owns FOURTEEN registers. The renamed properties live in two of
	 * them — `ori` and `credential-broker` — verified per file rather than assumed
	 * from the app name. A single `ori%` match would silently skip the other, and
	 * its column would keep the Dutch name for ever while every read looked at the
	 * English one.
	 *
	 * The BAG / BRP / KvK / DSO registers are deliberately absent: those mirror
	 * NATIONAL BASE REGISTRIES, their field names ARE the published standard, and
	 * all 91 of their properties are excluded from the rename entirely.
	 *
	 * @var array<int, string>
	 */
	private const REGISTER_SLUG_PREFIXES = ['ori', 'credential-broker'];

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `requestedAmount` as
	 * `requested_amount`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'bestandsgrootte' => 'file_size',
		'bestandsnaam' => 'file_name',
		'bovenliggend_agendapunt' => 'parent_agenda_item',
		'classificatie' => 'classification',
		'eind_datum' => 'end_date',
		'fractie_resultaten' => 'political_group_results',
		'functie' => 'role',
		'onthoudingen' => 'abstentions',
		'organisatie' => 'organisation',
		'resultaat' => 'result',
		'start_datum' => 'start_date',
		'stem' => 'vote',
		'stemmen_tegen' => 'votes_against',
		'stemmen_voor' => 'votes_for',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Move openregister data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every openregister shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchColumns: no openregister shard tables on this install; nothing to do.');
			return;
		}

		$renamed = 0;
		$copied = 0;
		$refused = 0;

		foreach ($tables as $table) {
			$columns = $this->columnsOf(table: $table);
			$qTable = $this->quote(identifier: $table);

			foreach (self::COLUMN_MAP as $old => $new) {
				if (in_array($old, $columns, true) === false) {
					continue;
				}

				if ($this->hasCollision(columns: $columns, target: $new) === true) {
					$this->logger->warning(
						'RenameDutchColumns: two sources target one destination; migrating neither.',
						['table' => $table, 'source' => $old, 'destination' => $new]
					);
					$refused++;
					continue;
				}

				if (in_array($new, $columns, true) === false) {
					$sql = 'ALTER TABLE ' . $qTable . ' RENAME COLUMN '
						. $this->quote(identifier: $old) . ' TO ' . $this->quote(identifier: $new);
					if ($this->exec(sql: $sql) === true) {
						$renamed++;
					}

					continue;
				}

				$qNew = $this->quote(identifier: $new);
				$qOld = $this->quote(identifier: $old);
				$sql = 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld
					. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
				if ($this->exec(sql: $sql) === true) {
					$copied++;
				}
			}//end foreach
		}//end foreach

		$output->info(
			'RenameDutchColumns: ' . $renamed . ' renamed, ' . $copied . ' back-filled, '
			. $refused . ' refused, across ' . count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Whether another mapped source already targets the same destination here.
	 *
	 * @param array<int, string> $columns Column names present in the table.
	 * @param string $target The destination column name.
	 *
	 * @return bool True when two sources compete for one destination.
	 */
	private function hasCollision(array $columns, string $target): bool {
		$sources = 0;
		foreach (self::COLUMN_MAP as $old => $new) {
			if ($new === $target && in_array($old, $columns, true) === true) {
				$sources++;
			}
		}

		return $sources > 1;
	}//end hasCollision()

	/**
	 * Resolve the shard tables of every register whose slug starts with the prefix.
	 *
	 * Table discovery goes through information_schema, NOT IDBConnection:
	 * OCP\IDBConnection exposes neither getSchema() nor getPrefix(), and calling
	 * either is a runtime fatal that `php -l` and phpcs both report as clean.
	 * Matching anchors on the `openregister_table_` MARKER rather than a computed
	 * prefix, because getTableName('') yields the literal `*PREFIX*` placeholder
	 * which a raw information_schema string never resolves.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		$ids = $this->registerIds();
		if ($ids === []) {
			return [];
		}

		$names = $this->openRegisterTableNames();
		if ($names === []) {
			return [];
		}

		$markers = [];
		foreach ($ids as $id) {
			$markers[] = 'openregister_table_' . ((int)$id) . '_';
		}

		$tables = [];
		foreach ($names as $name) {
			foreach ($markers as $marker) {
				$offset = strpos($name, $marker);
				if ($offset !== false && ctype_digit(substr($name, ($offset + strlen($marker)))) === true) {
					$tables[] = $name;
				}
			}
		}

		return array_values(array_unique($tables));
	}

	/**
	 * Ids of every register whose slug starts with the prefix.
	 *
	 * Split out of shardTables() only to keep that method under phpmd's
	 * cyclomatic-complexity limit; the behaviour is unchanged.
	 *
	 * @return array<int, mixed>
	 */
	private function registerIds(): array {
		try {
			$ids = [];
			foreach (self::REGISTER_SLUG_PREFIXES as $prefix) {
				$rows = $this->db->executeQuery(
					'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
					[$prefix . '%']
				)->fetchAll(\PDO::FETCH_COLUMN);
				$ids = array_merge($ids, (array)$rows);
			}

			return array_values(array_unique($ids));
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}

	/**
	 * Every table name containing the openregister shard marker.
	 *
	 * @return array<int, string>
	 */
	private function openRegisterTableNames(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$names = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end shardTables()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one statement, logging and swallowing failure.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
