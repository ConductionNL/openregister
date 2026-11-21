<?php

/**
 * TextSearchConfiguration — the PostgreSQL text search configuration search stems with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use InvalidArgumentException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Throwable;

/**
 * The setting `search.textSearchConfig` and what it means on this database.
 *
 * 🔑 POSTGRESQL ONLY, AND SAID SO. On PostgreSQL `_search` also matches the
 * stemmed form through `to_tsvector(<config>, ...) @@ plainto_tsquery(<config>, ...)`;
 * MySQL and MariaDB keep substring matching, and {@see self::stemming()}
 * answers `false` there so a client can say so.
 *
 * 🔑 THE NAME IS INLINED, SO IT IS AN IDENTIFIER OR NOTHING. A functional GIN
 * index only serves a query whose expression names the same configuration as a
 * literal, so the name is written into the SQL, never bound. A stored value
 * that is not a plain lower-case identifier is ignored in favour of the
 * default; a submitted one is refused unless `pg_ts_config` lists it.
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
class TextSearchConfiguration {

	/**
	 * The app config key.
	 *
	 * @var string
	 */
	public const KEY = 'search.textSearchConfig';

	/**
	 * The configuration used when none was saved.
	 *
	 * @var string
	 */
	public const DEFAULT = 'dutch';

	/**
	 * The app the setting belongs to.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * What a configuration name may look like before it is written into SQL.
	 *
	 * @var string
	 */
	private const IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}$/';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db        The connection, for the platform and `pg_ts_config`.
	 * @param IAppConfig    $appConfig Where the setting is stored.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether the database is PostgreSQL, the only platform that stems.
	 *
	 * @return bool True on PostgreSQL.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function isPostgres(): bool {
		return stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;
	}//end isPostgres()

	/**
	 * The saved configuration, or the default when none (or an unusable one) was saved.
	 *
	 * @return string The configuration name, a plain identifier.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function configured(): string {
		$stored = strtolower(trim($this->appConfig->getValueString(self::APP, self::KEY, self::DEFAULT)));
		if (preg_match(self::IDENTIFIER, $stored) !== 1) {
			return self::DEFAULT;
		}

		return $stored;
	}//end configured()

	/**
	 * The configuration search stems with here: the saved one on PostgreSQL, null elsewhere.
	 *
	 * @return string|null The configuration name, or null when this database does not stem.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function active(): ?string {
		if ($this->isPostgres() === false) {
			return null;
		}

		return $this->configured();
	}//end active()

	/**
	 * What the capabilities and `GET /api/settings/search` report as `stemming`.
	 *
	 * @return string|false The configuration on PostgreSQL, false elsewhere.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function stemming(): string|false {
		return ($this->active() ?? false);
	}//end stemming()

	/**
	 * The configurations the database reports in `pg_ts_config`; none off PostgreSQL.
	 *
	 * @return list<string> The names, sorted.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function available(): array {
		if ($this->isPostgres() === false) {
			return [];
		}

		try {
			$result = $this->db->executeQuery('SELECT cfgname FROM pg_catalog.pg_ts_config ORDER BY cfgname');
			$names  = $result->fetchAll(\PDO::FETCH_COLUMN);
			$result->closeCursor();
		} catch (Throwable $e) {
			return [];
		}

		return array_values(array_map('strval', $names));
	}//end available()

	/**
	 * Save a configuration after checking the database knows it.
	 *
	 * @param mixed $name The submitted name.
	 *
	 * @return string The saved name.
	 *
	 * @throws InvalidArgumentException When this database does not stem, or does not list the name.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function save(mixed $name): string {
		$name = $this->validated(name: $name);
		$this->appConfig->setValueString(self::APP, self::KEY, $name);

		return $name;
	}//end save()

	/**
	 * A submitted name, refused unless it is an identifier `pg_ts_config` lists.
	 *
	 * @param mixed $name The submitted name.
	 *
	 * @return string The name, lower case.
	 *
	 * @throws InvalidArgumentException When it is not usable here.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function validated(mixed $name): string {
		if ($this->isPostgres() === false) {
			throw new InvalidArgumentException(self::KEY.' applies to PostgreSQL only; this database does not stem.');
		}

		if (is_string($name) === false) {
			$name = '';
		}

		$name = strtolower(trim($name));
		if (preg_match(self::IDENTIFIER, $name) !== 1 || in_array($name, $this->available(), true) === false) {
			throw new InvalidArgumentException(
				'Unknown text search configuration for '.self::KEY.'. Known: '.implode(', ', $this->available()).'.'
			);
		}

		return $name;
	}//end validated()

	/**
	 * The tsvector expression over one text column, as the GIN index and the query write it.
	 *
	 * @param string $config The configuration name, an identifier from {@see self::active()}.
	 * @param string $column The quoted column reference.
	 *
	 * @return string The SQL expression.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public static function vector(string $config, string $column): string {
		return "to_tsvector('".$config."', COALESCE(CAST(".$column." AS TEXT), ''))";
	}//end vector()

	/**
	 * The tsvector expression over name, description and summary, as the GIN index and the query write it.
	 *
	 * @param string $config The configuration name, an identifier from {@see self::active()}.
	 * @param string $prefix The table alias with its dot (`t.`), or empty for the index definition.
	 *
	 * @return string The SQL expression.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public static function metadataVector(string $config, string $prefix=''): string {
		$parts = [];
		foreach (['_name', '_description', '_summary'] as $column) {
			$parts[] = "COALESCE(CAST(".$prefix.$column." AS TEXT), '')";
		}

		return "to_tsvector('".$config."', ".implode(" || ' ' || ", $parts).")";
	}//end metadataVector()

	/**
	 * The functional GIN index a magic table carries for the stemmed metadata match.
	 *
	 * @param string $config        The configuration name, an identifier from {@see self::active()}.
	 * @param string $tableName     The table name without the prefix; the index is named after it.
	 * @param string $fullTableName The table name with the prefix.
	 *
	 * @return string The `CREATE INDEX IF NOT EXISTS` statement.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public static function metadataIndexSql(string $config, string $tableName, string $fullTableName): string {
		return 'CREATE INDEX IF NOT EXISTS '.$tableName.'_tsv_idx ON '.$fullTableName
			.' USING GIN ('.self::metadataVector(config: $config).')';
	}//end metadataIndexSql()

	/**
	 * The query side of the match, with the term already quoted by the caller.
	 *
	 * @param string $config     The configuration name.
	 * @param string $quotedTerm The term as an SQL literal or a bound parameter placeholder.
	 *
	 * @return string The SQL expression.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public static function query(string $config, string $quotedTerm): string {
		return "plainto_tsquery('".$config."', ".$quotedTerm.")";
	}//end query()
}//end class
