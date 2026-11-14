<?php

/**
 * Create the unaccent extension and the openregister_fold() wrapper on PostgreSQL.
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
 * @spec openspec/changes/search-accent-insensitive/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Exception;
use OCA\OpenRegister\Db\MagicMapper\SearchFolding;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Accent folding for object search on PostgreSQL.
 *
 * `unaccent()` itself is only STABLE, so no index can use it. The
 * dictionary-qualified call inside an IMMUTABLE SQL function is the standard
 * way to make folded text indexable. A failure (usually missing rights) is
 * reported and skipped, the way the pg_trgm bootstrap degrades: search then
 * keeps the accent-sensitive comparison and the setting reports why.
 * MariaDB and MySQL fold through a collation and need nothing here.
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
 */
class Version1Date20261113140300 extends SimpleMigrationStep {

	/**
	 * The wrapper's definition.
	 */
	public const CREATE_FUNCTION = 'CREATE OR REPLACE FUNCTION ' . SearchFolding::FOLD_FUNCTION . '(text) RETURNS text'
		. ' LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT'
		. " AS \$\$ SELECT lower(public.unaccent('public.unaccent', \$1)) \$\$";

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection Database connection
	 */
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Ensure unaccent and the fold wrapper exist after schema changes (PostgreSQL only).
	 *
	 * @param IOutput $output        Migration output
	 * @param Closure $schemaClosure Schema closure
	 * @param array   $options       Migration options
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-accent-insensitive/tasks.md#1.1
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$platform = $this->connection->getDatabasePlatform();

		if (str_contains(get_class($platform), 'PostgreSQL') === false) {
			$output->info('Skipping unaccent bootstrap: accent folding needs no extension on this database platform');
			return;
		}

		try {
			$this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS unaccent');
		} catch (Exception $e) {
			if ($this->extensionInstalled() === false) {
				$output->warning(
					'unaccent extension is not installed and could not be created ('
					. $e->getMessage()
					. '). Object search stays accent-sensitive. '
					. 'Run CREATE EXTENSION unaccent; as a database owner and re-run this migration to enable it.'
				);
				return;
			}
		}

		try {
			$this->connection->executeStatement(self::CREATE_FUNCTION);
			$output->info('unaccent extension and ' . SearchFolding::FOLD_FUNCTION . '() are available (accent-insensitive search)');
		} catch (Exception $e) {
			$output->warning(
				'Could not create ' . SearchFolding::FOLD_FUNCTION . '() ('
				. $e->getMessage()
				. '). Object search stays accent-sensitive.'
			);
		}
	}//end postSchemaChange()

	/**
	 * Whether unaccent is installed already; a failed check counts as no.
	 *
	 * @return bool
	 */
	private function extensionInstalled(): bool {
		try {
			$result = $this->connection->executeQuery("SELECT 1 FROM pg_extension WHERE extname = 'unaccent'");

			return $result->fetchOne() !== false;
		} catch (Exception $e) {
			return false;
		}
	}//end extensionInstalled()
}//end class
