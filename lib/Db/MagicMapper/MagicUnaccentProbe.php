<?php

/**
 * MagicUnaccentProbe: whether the database can fold accents in a search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Answer once per instance whether accent folding is available, and why not.
 *
 * PostgreSQL needs the `openregister_fold()` wrapper the migration creates over
 * the `unaccent` extension. MariaDB and MySQL fold through a collation and need
 * nothing. Any other platform cannot fold.
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
 */
final class MagicUnaccentProbe {

	/**
	 * The reason given when PostgreSQL lacks the wrapper.
	 */
	public const REASON_NO_EXTENSION = 'the unaccent extension could not be created';

	/**
	 * The reason given on a platform that cannot fold.
	 */
	public const REASON_UNSUPPORTED = 'the database platform does not support accent-insensitive search';

	/**
	 * The reason, '' when available; null until asked.
	 *
	 * @var string|null
	 */
	private ?string $reason = null;

	/**
	 * Build the probe.
	 *
	 * @param IDBConnection   $db     The connection.
	 * @param LoggerInterface $logger Receives a failed check.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether accent folding is available; a failed check counts as no.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function available(): bool {
		return $this->reason() === '';
	}//end available()

	/**
	 * Why accent folding is unavailable, or '' when it is available.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function reason(): string {
		$this->reason ??= $this->ask();

		return $this->reason;
	}//end reason()

	/**
	 * The folding for this connection's platform.
	 *
	 * Folded only when available and wanted; otherwise the exact comparison.
	 *
	 * @param bool $wanted Whether the caller wants accents ignored.
	 *
	 * @return SearchFolding
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md
	 */
	public function folding(bool $wanted): SearchFolding {
		$platform = $this->db->getDatabasePlatform();
		$name     = SearchFolding::PLATFORM_OTHER;
		if ($platform instanceof PostgreSQLPlatform === true) {
			$name = SearchFolding::PLATFORM_POSTGRES;
		} else if ($this->isMysql(platform: $platform) === true) {
			$name = SearchFolding::PLATFORM_MYSQL;
		}

		return new SearchFolding(platform: $name, folded: ($wanted === true && $this->available() === true));
	}//end folding()

	/**
	 * Ask the database.
	 *
	 * @return string The reason, '' when available.
	 */
	private function ask(): string {
		$platform = $this->db->getDatabasePlatform();
		if ($this->isMysql(platform: $platform) === true) {
			return '';
		}

		if ($platform instanceof PostgreSQLPlatform === false) {
			return self::REASON_UNSUPPORTED;
		}

		try {
			$stmt = $this->db->prepare(
				"SELECT COUNT(*) FROM pg_proc WHERE proname = '" . SearchFolding::FOLD_FUNCTION . "'"
			);
			if ((int)$stmt->execute()->fetchOne() > 0) {
				return '';
			}
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Failed to check accent folding availability',
				context: ['error' => $e->getMessage()]
			);
		}

		return self::REASON_NO_EXTENSION;
	}//end ask()

	/**
	 * Whether a platform is MariaDB or MySQL, by class name so it holds across
	 * DBAL versions (MariaDb1027Platform, MySQL80Platform, MariaDBPlatform, ...).
	 *
	 * @param object $platform The database platform.
	 *
	 * @return bool
	 */
	private function isMysql(object $platform): bool {
		return preg_match('/MySQL|MariaDB/i', get_class($platform)) === 1;
	}//end isMysql()
}//end class
