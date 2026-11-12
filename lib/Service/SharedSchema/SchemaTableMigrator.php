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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
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
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
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
	 * @param MagicMapperInterface     $magicMapper Magic-table DDL and introspection.
	 * @param LoggerInterface $logger      Audit trail for every mutation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly MagicMapperInterface $magicMapper,
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
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public static function planColumnMapping(array $sourceColumns, array $targetColumns): array {
		$target  = array_map('strtolower', array_map('strval', $targetColumns));
		$columns = array_filter(array_map('strval', $sourceColumns), static fn (string $column): bool => $column !== '_id');
		$mapped  = array_filter($columns, static fn (string $column): bool => in_array(strtolower($column), $target, true));
		$dropped = array_filter($columns, static fn (string $column): bool => in_array(strtolower($column), $target, true) === false);

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
	 * @param TableCopy $copy  The source table, the target table and the columns to copy.
	 * @param string    $quote The identifier quote character.
	 *
	 * @return string The statement.
	 *
	 * @throws RuntimeException When there are no columns, or an identifier is unsafe.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public static function buildCopySql(TableCopy $copy, string $quote='"'): string {
		if ($copy->columns === []) {
			throw new RuntimeException('Refusing to build a copy statement with no columns.');
		}

		$list = implode(', ', self::quoteColumns(columns: $copy->columns, quote: $quote));

		return sprintf(
			'INSERT INTO %s (%s) SELECT %s FROM %s',
			self::quoteIdentifier(name: $copy->targetTable, quote: $quote),
			$list,
			$list,
			self::quoteIdentifier(name: $copy->sourceTable, quote: $quote)
		);
	}//end buildCopySql()

	/**
	 * Quote each column name for interpolation, refusing an unsafe one.
	 *
	 * @param array  $columns The column names.
	 * @param string $quote   The identifier quote character.
	 *
	 * @return string[] The quoted column names.
	 *
	 * @throws RuntimeException When a column name is unsafe.
	 */
	private static function quoteColumns(array $columns, string $quote): array {
		$quoted = [];
		foreach ($columns as $column) {
			$quoted[] = self::quoteIdentifier(name: (string)$column, quote: $quote);
		}

		return $quoted;
	}//end quoteColumns()

	/**
	 * The bare magic-table name for a register/schema pair.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId   The schema id.
	 *
	 * @return string The unprefixed table name.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
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
	 * @param SplitTableColumns $split The bare source table, the register's configured definition (null when
	 *                                 the split falls back to cloning) and the current shared entity content.
	 *
	 * @return string[] Source columns with no destination.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function planUnmapped(SplitTableColumns $split): array {
		$source = $this->columnsOf(table: $split->table);
		if ($source === []) {
			$source = $this->columnsForDefinition(definition: $split->content);
		}

		$target = $split->content;
		if ($split->definition !== null) {
			$target = $split->definition;
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
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
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
	 * @param RegisterSchema $table The register being split off and its new schema.
	 * @param int            $oldId The shared schema id being left behind.
	 *
	 * @return array{rows: int, unmapped: string[], backup: string|null} How many rows moved,
	 *         which source columns had no destination, and where the source table went.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function migrate(RegisterSchema $table, int $oldId): array {
		$restamp = new RowRestamp(registerId: $table->registerId(), oldId: $oldId, newId: $table->schemaId());

		if ($this->tableExists(table: ($this->prefix() . $this->sourceTableOf(restamp: $restamp))) === false) {
			return ['rows' => 0, 'unmapped' => [], 'backup' => null];
		}

		return $this->moveRows(table: $table, restamp: $restamp);
	}//end migrate()

	/**
	 * The bare name of the shared table a register's rows are moved out of.
	 *
	 * @param RowRestamp $restamp The register id and the shared schema id being left behind.
	 *
	 * @return string The bare (unprefixed) source table name.
	 */
	private function sourceTableOf(RowRestamp $restamp): string {
		return $this->tableNameFor(registerId: $restamp->registerId, schemaId: $restamp->oldId);
	}//end sourceTableOf()

	/**
	 * Copy, restamp and set aside an existing source table, and log the move.
	 *
	 * @param RegisterSchema $table   The register being split off and its new schema.
	 * @param RowRestamp     $restamp The register id and the old and new schema ids.
	 *
	 * @return array{rows: int, unmapped: string[], backup: string} The outcome of the move.
	 */
	private function moveRows(RegisterSchema $table, RowRestamp $restamp): array {
		$prefix     = $this->prefix();
		$sourceBare = $this->sourceTableOf(restamp: $restamp);
		$targetBare = $this->ensureTargetTable(table: $table);
		$mapping    = $this->mapColumns(sourceTable: $sourceBare, targetTable: $targetBare);
		$copy       = new TableCopy(sourceTable: ($prefix . $sourceBare), targetTable: ($prefix . $targetBare), columns: $mapping['mapped']);
		$rows       = $this->copyAndRestamp(copy: $copy, restamp: $restamp);
		$result     = ['rows' => $rows, 'unmapped' => $mapping['unmapped'], 'backup' => $this->setAside(source: $copy->sourceTable)];

		$this->logger->warning(
			message: self::moveSummary(copy: $copy, result: $result),
			context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $restamp->registerId, 'schema' => $restamp->oldId]
		);

		return $result;
	}//end moveRows()

	/**
	 * Make sure the replacement schema's table exists and return its bare name.
	 *
	 * @param RegisterSchema $table The register and its new schema.
	 *
	 * @return string The bare (unprefixed) target table name.
	 */
	private function ensureTargetTable(RegisterSchema $table): string {
		$this->magicMapper->ensureTableForRegisterSchema(register: $table->register, schema: $table->schema);
		return $this->magicMapper->getTableNameForRegisterSchema(register: $table->register, schema: $table->schema);
	}//end ensureTargetTable()

	/**
	 * Map the source table's columns onto the target table's columns.
	 *
	 * @param string $sourceTable The bare source table name.
	 * @param string $targetTable The bare target table name.
	 *
	 * @return array{mapped: string[], unmapped: string[]} The column mapping.
	 */
	private function mapColumns(string $sourceTable, string $targetTable): array {
		return self::planColumnMapping(
			sourceColumns: $this->columnsOf(table: $sourceTable),
			targetColumns: $this->columnsOf(table: $targetTable)
		);
	}//end mapColumns()

	/**
	 * Copy the mapped rows into the target table, then repoint their schema references.
	 *
	 * @param TableCopy  $copy    The source and target tables and the columns to copy.
	 * @param RowRestamp $restamp The register id and the old and new schema ids.
	 *
	 * @return int The number of rows restamped.
	 */
	private function copyAndRestamp(TableCopy $copy, RowRestamp $restamp): int {
		$this->db->executeStatement(self::buildCopySql(copy: $copy, quote: $this->quoteChar()));

		return $this->restamp(table: $copy->targetTable, restamp: $restamp);
	}//end copyAndRestamp()

	/**
	 * Rename the source table to its backup name.
	 *
	 * @param string $source The fully qualified source table.
	 *
	 * @return string The fully qualified backup table name.
	 */
	private function setAside(string $source): string {
		$quote  = $this->quoteChar();
		$backup = ($source . self::BACKUP_SUFFIX);
		$this->db->executeStatement(
			sprintf(
				'ALTER TABLE %s RENAME TO %s',
				self::quoteIdentifier(name: $source, quote: $quote),
				self::quoteIdentifier(name: $backup, quote: $quote)
			)
		);

		return $backup;
	}//end setAside()

	/**
	 * Describe a completed move for the operator log.
	 *
	 * @param TableCopy                                                   $copy   The tables the rows moved between.
	 * @param array{rows: int, unmapped: string[], backup: string|null} $result The outcome of the move.
	 *
	 * @return string The log message.
	 */
	private static function moveSummary(TableCopy $copy, array $result): string {
		return sprintf(
			'[SharedSchemaDedupe] Moved %d row(s) from %s to %s; source kept as %s; %d unmapped column(s): %s.',
			$result['rows'],
			$copy->sourceTable,
			$copy->targetTable,
			$result['backup'],
			count($result['unmapped']),
			implode(', ', $result['unmapped'])
		);
	}//end moveSummary()

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
	 * @param string     $table   The fully qualified target table.
	 * @param RowRestamp $restamp The owning register id (which bounds the uri rewrite), the shared
	 *                            schema id and the register's new schema id.
	 *
	 * @return int The number of rows restamped.
	 */
	private function restamp(string $table, RowRestamp $restamp): int {
		$quoted = self::quoteIdentifier(name: $table, quote: $this->quoteChar());
		$oldId  = $restamp->oldId;
		$newId  = $restamp->newId;

		$rows = $this->db->executeStatement(
			sprintf('UPDATE %s SET _schema = :new WHERE _schema = :old', $quoted),
			['new' => (string)$newId, 'old' => (string)$oldId]
		);

		$this->rewriteUris(table: $table, restamp: $restamp);

		return $rows;
	}//end restamp()

	/**
	 * Repoint the register-bounded `_uri` segment of the copied rows at the new schema id.
	 *
	 * @param string     $table   The fully qualified target table.
	 * @param RowRestamp $restamp The register id and the old and new schema ids.
	 *
	 * @return void
	 */
	private function rewriteUris(string $table, RowRestamp $restamp): void {
		$quoted = self::quoteIdentifier(name: $table, quote: $this->quoteChar());

		try {
			$this->db->executeStatement(
				sprintf('UPDATE %s SET _uri = REPLACE(_uri, :old, :new) WHERE _uri LIKE :match', $quoted),
				self::uriRewriteParameters(restamp: $restamp)
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
	}//end rewriteUris()

	/**
	 * The bound parameters of the `_uri` rewrite.
	 *
	 * @param RowRestamp $restamp The register id and the old and new schema ids.
	 *
	 * @return array{old: string, new: string, match: string} The parameters.
	 */
	private static function uriRewriteParameters(RowRestamp $restamp): array {
		return [
			'old'   => sprintf('/%d/%d/', $restamp->registerId, $restamp->oldId),
			'new'   => sprintf('/%d/%d/', $restamp->registerId, $restamp->newId),
			'match' => sprintf('%%/%d/%d/%%', $restamp->registerId, $restamp->oldId),
		];
	}//end uriRewriteParameters()

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
