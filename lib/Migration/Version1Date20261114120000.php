<?php

/**
 * Database migration adding the functional GIN indexes the stemmed `_search` arm reads.
 *
 * Part of search-dutch-language-quality (REQ-SDL-001): on PostgreSQL `_search`
 * also matches `to_tsvector(<config>, ...) @@ plainto_tsquery(<config>, ...)`,
 * and ChunkMapper::searchByKeyword() does the same over chunk text. These are
 * expression indexes, like Version1Date20260706101000's: no column is added, so
 * Doctrine schema introspection never sees a tsvector type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds, on PostgreSQL only, the stemmed-match GIN index on the chunk text and on
 * every existing magic table's name, description and summary.
 *
 * New magic tables get the same index from MagicTableIndexer. Each index is
 * created when missing (`IF NOT EXISTS`); a failure is a warning, never a
 * failed upgrade: without the index the match is still right, only slower.
 * The configuration is the saved `search.textSearchConfig` (default `dutch`).
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TextSearchConfiguration::metadataIndexSql() is a pure SQL builder.
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
class Version1Date20261114120000 extends SimpleMigrationStep {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection           $connection Database connection.
	 * @param IConfig                 $config     Nextcloud config, for the table prefix.
	 * @param TextSearchConfiguration $textSearch The configuration the indexes are built with.
	 */
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
		private readonly TextSearchConfiguration $textSearch,
	) {
	}//end __construct()

	/**
	 * Add the indexes after the schema changes (PostgreSQL only).
	 *
	 * @param IOutput $output        Migration output.
	 * @param Closure $schemaClosure Schema closure.
	 * @param array   $options       Migration options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$config = $this->textSearch->active();
		if ($config === null) {
			$output->info('Skipping stemmed search indexes: unsupported database platform (PostgreSQL only)');
			return;
		}

		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		if ($schemaClosure()->hasTable('openregister_chunks') === true) {
			$this->statement(
				output: $output,
				sql: 'CREATE INDEX IF NOT EXISTS idx_or_chunks_text_search_'.$config.'_gin ON '.$prefix.'openregister_chunks'
					." USING gin (to_tsvector('".$config."', text_content))"
			);
		}

		foreach ($this->magicTables(prefix: $prefix) as $tableName) {
			$this->statement(
				output: $output,
				sql: TextSearchConfiguration::metadataIndexSql(config: $config, tableName: $tableName, fullTableName: $prefix.$tableName)
			);
		}
	}//end postSchemaChange()

	/**
	 * The magic tables that exist, without the prefix.
	 *
	 * @param string $prefix The table prefix.
	 *
	 * @return list<string> The bare table names.
	 */
	private function magicTables(string $prefix): array {
		$pattern = str_replace('_', '\\_', $prefix.MagicMapperInterface::TABLE_PREFIX).'%';
		try {
			$result = $this->connection->executeQuery(
				'SELECT tablename FROM pg_catalog.pg_tables WHERE tablename LIKE :pattern ORDER BY tablename',
				['pattern' => $pattern]
			);
			$names  = $result->fetchAll(\PDO::FETCH_COLUMN);
			$result->closeCursor();
		} catch (Exception $e) {
			return [];
		}

		$tables = [];
		foreach ($names as $name) {
			$tables[] = substr((string) $name, strlen($prefix));
		}

		return $tables;
	}//end magicTables()

	/**
	 * Run one index statement; a failure is reported, not raised.
	 *
	 * @param IOutput $output Migration output.
	 * @param string  $sql    The statement.
	 *
	 * @return void
	 */
	private function statement(IOutput $output, string $sql): void {
		try {
			$this->connection->executeStatement($sql);
		} catch (Exception $e) {
			$output->warning('Failed to create a stemmed search index ('.$e->getMessage().'); search stays correct, only slower.');
		}
	}//end statement()
}//end class
