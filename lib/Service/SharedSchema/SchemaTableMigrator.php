<?php

/**
 * OpenRegister SchemaTableMigrator
 *
 * Moves a register's object rows from the magic table of a shared schema onto
 * the magic table of the register's own replacement schema, mapping columns and
 * reporting — never silently dropping — the ones with no destination.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SharedSchema;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Carry object rows across a schema split.
 *
 * Objects live in per-pair tables `<prefix>openregister_table_<register>_<schema>`,
 * so when a register is repointed at a new schema id its rows must follow. A bare
 * `ALTER TABLE ... RENAME` — what the older `openregister:schemas:dedup` does — is
 * only correct while the new schema is a byte-copy of the old one. It is exactly
 * wrong for the case this repair exists for: the replacement schema is rebuilt
 * from the register's OWN configuration, so its table has the columns the shared
 * entity had overwritten away, and lacks the ones that belonged to the other app.
 * The move therefore has to be a column-mapped INSERT-SELECT.
 *
 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
 */
class SchemaTableMigrator {

	/**
	 * Bare (unprefixed) magic-table name stem.
	 *
	 * @var string
	 */
	public const TABLE_STEM = 'openregister_table_';

	/**
	 * Suffix appended to a source table once its rows have been copied away.
	 *
	 * Chosen so the result no longer matches the shard pattern
	 * `<prefix>openregister_table_<int>_<int>` that
	 * {@see \OCA\OpenRegister\Service\RegisterSchemaLinkageRepairService} treats as
	 * evidence of a pairing. A source table left under its original name would
	 * make `relink-schemas` propose re-linking the register to the very schema
	 * this repair just split it away from — the sibling command would quietly undo
	 * this one. Keeping the table (rather than dropping it) is what makes an
	 * unmapped column recoverable instead of lost.
	 *
	 * @var string
	 */
	public const BACKUP_SUFFIX = '_predupe';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db          Database connection for the row move.
	 * @param IConfig         $config      System config, read for `dbtableprefix`.
	 * @param MagicMapper     $magicMapper Magic-table DDL and introspection.
	 * @param LoggerInterface $logger      Audit trail for every mutation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly MagicMapper $magicMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Work out which source columns survive the move to the new table.
	 *
	 * `_id` is excluded on purpose. It is an autoincrement primary key; copying
	 * the values verbatim would leave the target's sequence behind the highest
	 * copied id, so the next insert into the repaired table would collide. `_uuid`
	 * is the identity relations actually store, and it IS copied.
	 *
	 * Matching is case-insensitive because `information_schema` folds identifier
	 * case differently per platform, and a case mismatch here would report every
	 * column as unmapped — which under `--strict` would refuse every otherwise
	 * healthy split.
	 *
	 * @param string[] $sourceColumns Column names of the table holding the rows.
	 * @param string[] $targetColumns Column names of the table built for the new schema.
	 *
	 * @return array{mapped: string[], unmapped: string[]} Columns that move, and source
	 *         columns with no destination.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public static function planColumnMapping(array $sourceColumns, array $targetColumns): array {
		$target  = array_map('strtolower', array_map('strval', $targetColumns));
		$mapped  = [];
		$dropped = [];

		foreach ($sourceColumns as $column) {
			$column = (string)$column;
			if ($column === '_id') {
				continue;
			}

			if (in_array(strtolower($column), $target, true) === true) {
				$mapped[] = $column;
				continue;
			}

			$dropped[] = $column;
		}

		sort($mapped);
		sort($dropped);

		return ['mapped' => $mapped, 'unmapped' => $dropped];
	}//end planColumnMapping()

	/**
	 * Build the INSERT-SELECT that moves the mapped columns.
	 *
	 * Identifiers cannot be bound as parameters, so every name is validated
	 * against a plain-identifier pattern before it is interpolated. The quote
	 * character is passed in rather than detected here so the statement builder
	 * stays pure and testable.
	 *
	 * @param string   $sourceTable The fully qualified source table.
	 * @param string   $targetTable The fully qualified target table.
	 * @param string[] $columns     The columns to copy, in order.
	 * @param string   $quote       The identifier quote character.
	 *
	 * @return string The statement.
	 *
	 * @throws RuntimeException When there are no columns, or an identifier is unsafe.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public static function buildCopySql(
		string $sourceTable,
		string $targetTable,
		array $columns,
		string $quote='"'
	): string {
		if ($columns === []) {
			throw new RuntimeException('Refusing to build a copy statement with no columns.');
		}

		$quoted = [];
		foreach ($columns as $column) {
			$quoted[] = self::quoteIdentifier(name: (string)$column, quote: $quote);
		}

		$list = implode(', ', $quoted);

		return sprintf(
			'INSERT INTO %s (%s) SELECT %s FROM %s',
			self::quoteIdentifier(name: $targetTable, quote: $quote),
			$list,
			$list,
			self::quoteIdentifier(name: $sourceTable, quote: $quote)
		);
	}//end buildCopySql()

	/**
	 * The bare magic-table name for a register/schema pair.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId   The schema id.
	 *
	 * @return string The unprefixed table name.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function tableNameFor(int $registerId, int $schemaId): string {
		return self::TABLE_STEM . $registerId . '_' . $schemaId;
	}//end tableNameFor()

	/**
	 * Name the source columns a split would leave behind, without writing anything.
	 *
	 * Predicting the target's columns at plan time is what lets `--strict` refuse
	 * BEFORE anything is created, and what lets the dry run name the columns that
	 * would be stranded. Deciding after the target table exists would leave a
	 * stray table behind on MySQL, where DDL does not roll back with the
	 * transaction.
	 *
	 * @param string            $table      The bare source table name.
	 * @param array<string, mixed> $definition The register's configured definition, or null
	 *                                      when the split falls back to cloning.
	 * @param array<string, mixed> $content The current shared entity content.
	 *
	 * @return string[] Source columns with no destination.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function planUnmapped(string $table, ?array $definition, array $content): array {
		$source = $this->columnsOf(table: $table);
		if ($source === []) {
			$source = $this->columnsForDefinition(definition: $content);
		}

		$target = $content;
		if ($definition !== null) {
			$target = $definition;
		}

		return self::planColumnMapping(
			sourceColumns: $source,
			targetColumns: $this->columnsForDefinition(definition: $target)
		)['unmapped'];
	}//end planUnmapped()

	/**
	 * List a bare table's column names, or an empty list when it is absent.
	 *
	 * @param string $table The bare (unprefixed) table name.
	 *
	 * @return string[] The column names.
	 */
	private function columnsOf(string $table): array {
		try {
			return array_map('strval', array_keys($this->magicMapper->getExistingTableColumns(tableName: $table)));
		} catch (Throwable $e) {
			unset($e);
			return [];
		}
	}//end columnsOf()

	/**
	 * Ask the magic-table column builder what a definition would materialise as.
	 *
	 * A transient, unpersisted {@see Schema} is hydrated purely so the real column
	 * builder answers the question — reimplementing the property-to-column rules
	 * here would drift from the DDL the split actually produces.
	 *
	 * @param array<string, mixed> $definition The schema definition.
	 *
	 * @return string[] The column names, or an empty list when the builder refuses.
	 */
	private function columnsForDefinition(array $definition): array {
		$transient = new Schema();

		try {
			$transient->hydrate($definition);
			return array_map(
				'strval',
				array_keys($this->magicMapper->buildTableColumnsFromSchema(schema: $transient))
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SharedSchemaDedupe] Could not predict columns: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__],
			);
			return [];
		}
	}//end columnsForDefinition()

	/**
	 * Count the rows in a bare magic table.
	 *
	 * @param string $table The bare (unprefixed) table name.
	 *
	 * @return int The row count, or -1 when the table is absent or unreadable.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function countRows(string $table): int {
		$full = ($this->prefix() . $table);
		if ($this->tableExists(table: $full) === false) {
			return -1;
		}

		try {
			$quoted = self::quoteIdentifier(name: $full, quote: $this->quoteChar());
			return (int)$this->db->executeQuery('SELECT COUNT(*) AS c FROM ' . $quoted)->fetchOne();
		} catch (Throwable $e) {
			unset($e);
			return -1;
		}
	}//end countRows()

	/**
	 * Move a register's rows onto the table of its replacement schema.
	 *
	 * @param Register $register The register being split off.
	 * @param Schema   $schema   The register's new schema.
	 * @param int      $oldId    The shared schema id being left behind.
	 *
	 * @return array{rows: int, unmapped: string[], backup: string|null} How many rows moved,
	 *         which source columns had no destination, and where the source table went.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function migrate(Register $register, Schema $schema, int $oldId): array {
		$registerId = (int)$register->getId();
		$sourceBare = $this->tableNameFor(registerId: $registerId, schemaId: $oldId);
		$source     = ($this->prefix() . $sourceBare);

		if ($this->tableExists(table: $source) === false) {
			return ['rows' => 0, 'unmapped' => [], 'backup' => null];
		}

		$this->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
		$targetBare = $this->magicMapper->getTableNameForRegisterSchema(register: $register, schema: $schema);

		$mapping = self::planColumnMapping(
			sourceColumns: $this->columnsOf(table: $sourceBare),
			targetColumns: $this->columnsOf(table: $targetBare)
		);

		$quote = $this->quoteChar();
		$this->db->executeStatement(
			self::buildCopySql(
				sourceTable: $source,
				targetTable: ($this->prefix() . $targetBare),
				columns: $mapping['mapped'],
				quote: $quote
			)
		);

		$rows = $this->restamp(
			table: ($this->prefix() . $targetBare),
			registerId: $registerId,
			oldId: $oldId,
			newId: (int)$schema->getId(),
			quote: $quote
		);

		$backup = ($source . self::BACKUP_SUFFIX);
		$this->db->executeStatement(
			sprintf(
				'ALTER TABLE %s RENAME TO %s',
				self::quoteIdentifier(name: $source, quote: $quote),
				self::quoteIdentifier(name: $backup, quote: $quote)
			)
		);

		$this->logger->warning(
			message: sprintf(
				'[SharedSchemaDedupe] Moved %d row(s) from %s to %s; source kept as %s; %d unmapped column(s): %s.',
				$rows,
				$source,
				($this->prefix() . $targetBare),
				$backup,
				count($mapping['unmapped']),
				implode(', ', $mapping['unmapped'])
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $registerId, 'schema' => $oldId]
		);

		return ['rows' => $rows, 'unmapped' => $mapping['unmapped'], 'backup' => $backup];
	}//end migrate()

	/**
	 * Repoint the copied rows' denormalised schema references at the new id.
	 *
	 * The table name is not the only place the pairing is recorded. Every row also
	 * carries `_schema`, and `_uri` embeds the schema id in the absolute URL the
	 * save path stores. Leaving either at the old value attributes the moved rows
	 * to the register that KEPT the shared schema — exactly the cross-app bleed
	 * this repair exists to end. Verified as a real failure mode on the larpingapp
	 * split, where 139 rows sat at the old `_schema` inside the renamed table.
	 *
	 * @param string $table      The fully qualified target table.
	 * @param int    $registerId The owning register id, which bounds the uri rewrite.
	 * @param int    $oldId      The shared schema id.
	 * @param int    $newId      The register's new schema id.
	 * @param string $quote      The identifier quote character.
	 *
	 * @return int The number of rows restamped.
	 */
	private function restamp(string $table, int $registerId, int $oldId, int $newId, string $quote): int {
		$quoted = self::quoteIdentifier(name: $table, quote: $quote);

		$rows = $this->db->executeStatement(
			sprintf('UPDATE %s SET _schema = :new WHERE _schema = :old', $quoted),
			['new' => (string)$newId, 'old' => (string)$oldId]
		);

		try {
			$this->db->executeStatement(
				sprintf('UPDATE %s SET _uri = REPLACE(_uri, :old, :new) WHERE _uri LIKE :match', $quoted),
				[
					'old'   => sprintf('/%d/%d/', $registerId, $oldId),
					'new'   => sprintf('/%d/%d/', $registerId, $newId),
					'match' => sprintf('%%/%d/%d/%%', $registerId, $oldId),
				]
			);
		} catch (Throwable $e) {
			// A stale `_uri` is a cosmetic link, not a correctness boundary: the
			// row is already attributed by `_schema` and by the table it lives in.
			// Failing the whole split over it would be worse than reporting it.
			$this->logger->warning(
				message: '[SharedSchemaDedupe] Could not rewrite _uri on ' . $table . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__],
			);
		}

		return $rows;
	}//end restamp()

	/**
	 * Check whether a fully qualified table exists.
	 *
	 * @param string $table The fully qualified table name.
	 *
	 * @return bool True when it exists.
	 */
	private function tableExists(string $table): bool {
		try {
			$stmt = $this->db->prepare(
				'SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1'
			);
			$stmt->execute([$table]);
			return $stmt->fetchOne() !== false;
		} catch (Throwable $e) {
			unset($e);
			return false;
		}
	}//end tableExists()

	/**
	 * The configured table prefix.
	 *
	 * @return string The prefix, defaulting to `oc_`.
	 */
	private function prefix(): string {
		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		if ($prefix === '') {
			return 'oc_';
		}

		return $prefix;
	}//end prefix()

	/**
	 * The identifier quote character for this platform.
	 *
	 * @return string A backtick on MySQL and MariaDB, a double quote elsewhere.
	 */
	private function quoteChar(): string {
		try {
			$platform = $this->db->getDatabasePlatform()::class;
		} catch (Throwable $e) {
			unset($e);
			return '"';
		}

		if (stripos($platform, 'MySQL') !== false || stripos($platform, 'MariaDB') !== false) {
			return '`';
		}

		return '"';
	}//end quoteChar()

	/**
	 * Quote a SQL identifier after validating it is a plain name.
	 *
	 * @param string $name  The identifier.
	 * @param string $quote The quote character.
	 *
	 * @return string The quoted identifier.
	 *
	 * @throws RuntimeException When the name is not a plain SQL identifier.
	 */
	private static function quoteIdentifier(string $name, string $quote): string {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
			throw new RuntimeException(sprintf('Refusing to quote unsafe identifier "%s".', $name));
		}

		return $quote . $name . $quote;
	}//end quoteIdentifier()
}//end class
