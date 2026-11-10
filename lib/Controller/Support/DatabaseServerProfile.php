<?php

/**
 * The database server behind this instance, with its vector-search advice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/production-observability/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Reads the type, version and extensions of the database server.
 *
 * @spec openspec/specs/production-observability/spec.md
 */
final class DatabaseServerProfile {

	/**
	 * What is reported for a platform nothing is known about.
	 *
	 * @var array<string, mixed>
	 */
	private const UNKNOWN = [
		'type' => 'Unknown',
		'version' => 'Unknown',
		'vectorSupport' => false,
		'recommendedPlugin' => null,
		'performanceNote' => null,
		'extensions' => [],
	];

	/**
	 * The advice for SQLite.
	 *
	 * @var array<string, string>
	 */
	private const SQLITE = [
		'type' => 'SQLite',
		'recommendedPlugin' => 'sqlite-vss or migrate to PostgreSQL',
		'performanceNote' => 'SQLite not recommended for production vector search.',
	];

	/**
	 * The advice for MySQL and MariaDB, which have no native vector operations.
	 *
	 * @var array<string, string>
	 */
	private const MYSQL = [
		'type' => 'MySQL/MariaDB',
		'recommendedPlugin' => 'pgvector for PostgreSQL',
		'performanceNote' => 'Current: Similarity calculated in PHP (slow).'
			. ' Recommended: Migrate to PostgreSQL + pgvector for 10-100x speedup.',
	];

	/**
	 * The advice for PostgreSQL without pgvector.
	 *
	 * @var array<string, string>
	 */
	private const POSTGRES = [
		'type' => 'PostgreSQL',
		'recommendedPlugin' => 'pgvector (not installed)',
		'performanceNote' => 'Install pgvector extension: CREATE EXTENSION vector;',
	];

	/**
	 * What changes for PostgreSQL once pgvector is installed.
	 *
	 * @var array<string, mixed>
	 */
	private const PGVECTOR = [
		'vectorSupport' => true,
		'recommendedPlugin' => 'pgvector (installed)',
		'performanceNote' => 'Optimal: Using database-level vector operations for fast semantic search.',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What is known about the active database server.
	 *
	 * @return array<string, mixed> Its type, version, platform, vector-search advice and extensions.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function describe(): array {
		$platformName = $this->platformName();
		$profile = $this->forPlatform(platformName: $platformName);

		return [
			'type' => $profile['type'],
			'version' => $profile['version'],
			'platform' => $platformName,
			'vectorSupport' => $profile['vectorSupport'],
			'recommendedPlugin' => $profile['recommendedPlugin'],
			'performanceNote' => $profile['performanceNote'],
			'extensions' => $profile['extensions'],
		];
	}//end describe()

	/**
	 * The name of the active database platform.
	 *
	 * The platform instance from getDatabasePlatform() is deliberately not type hinted.
	 *
	 * @return string The platform name, or 'unknown'.
	 */
	private function platformName(): string {
		$platform = $this->db->getDatabasePlatform();
		if (method_exists($platform, 'getName') === true) {
			return $platform->getName();
		}

		return 'unknown';
	}//end platformName()

	/**
	 * The database type, version, extensions and vector-search advice for a platform.
	 *
	 * @param string $platformName The active platform's name.
	 *
	 * @return array{type: string, version: string, vectorSupport: bool, recommendedPlugin: string|null,
	 *     performanceNote: string|null, extensions: list<array{name: mixed, version: mixed}>}
	 */
	private function forPlatform(string $platformName): array {
		if (strpos($platformName, 'mysql') !== false || strpos($platformName, 'mariadb') !== false) {
			return $this->mysql();
		}

		if (strpos($platformName, 'postgres') !== false) {
			return $this->postgres();
		}

		if (strpos($platformName, 'sqlite') !== false) {
			return array_replace(self::UNKNOWN, self::SQLITE);
		}

		return self::UNKNOWN;
	}//end forPlatform()

	/**
	 * The profile of a MySQL or MariaDB server.
	 *
	 * @return array<string, mixed> The profile.
	 */
	private function mysql(): array {
		$profile = array_replace(self::UNKNOWN, self::MYSQL);
		try {
			$version = $this->serverVersion();
			$profile['type'] = $this->mysqlFlavour(version: $version);
			$profile['version'] = $this->semanticVersion(version: $version);
		} catch (Exception $e) {
			// Reported as MySQL/MariaDB of unknown version.
			$this->logger->debug(
				message: '[DatabaseServerProfile] Could not read the MySQL server version',
				context: [
					'exception' => $e->getMessage(),
				]
			);
		}

		return $profile;
	}//end mysql()

	/**
	 * Which of MySQL and MariaDB a server version string names.
	 *
	 * @param mixed $version The server version string, as the driver answers it.
	 *
	 * @return string 'MariaDB' or 'MySQL'.
	 */
	private function mysqlFlavour(mixed $version): string {
		if (stripos($version, 'MariaDB') !== false) {
			return 'MariaDB';
		}

		return 'MySQL';
	}//end mysqlFlavour()

	/**
	 * The x.y.z part of a server version string, or the whole string when it has none.
	 *
	 * @param mixed $version The server version string, as the driver answers it.
	 *
	 * @return string The version.
	 */
	private function semanticVersion(mixed $version): string {
		preg_match('/\d+\.\d+\.\d+/', $version, $matches);
		return $matches[0] ?? $version;
	}//end semanticVersion()

	/**
	 * The profile of a PostgreSQL server, with its installed extensions and pgvector status.
	 *
	 * @return array<string, mixed> The profile.
	 */
	private function postgres(): array {
		$version = $this->postgresVersion();
		$extensions = $this->postgresExtensions();
		$profile = array_replace(self::UNKNOWN, self::POSTGRES, ['version' => $version, 'extensions' => $extensions]);
		if (in_array('vector', array_column($extensions, 'name'), true) === true) {
			return array_replace($profile, self::PGVECTOR);
		}

		return $profile;
	}//end postgres()

	/**
	 * The PostgreSQL version number.
	 *
	 * @return string The version, or 'Unknown'.
	 */
	private function postgresVersion(): string {
		try {
			preg_match('/PostgreSQL (\d+\.\d+)/', $this->serverVersion(), $matches);
			return ($matches[1] ?? 'Unknown');
		} catch (Exception $e) {
			return 'Unknown';
		}
	}//end postgresVersion()

	/**
	 * The database server's version string.
	 *
	 * @return mixed The result of SELECT VERSION().
	 */
	private function serverVersion() {
		$stmt = $this->db->prepare('SELECT VERSION()');
		$result = $stmt->execute();
		return $result->fetchOne();
	}//end serverVersion()

	/**
	 * The installed PostgreSQL extensions; what was read before a failure when the catalog breaks.
	 *
	 * @return list<array{name: mixed, version: mixed}>
	 */
	private function postgresExtensions(): array {
		$extensions = [];
		try {
			$stmt = $this->db->prepare('SELECT extname, extversion FROM pg_extension ORDER BY extname');
			$result = $stmt->execute();
			while (is_array($row = $result->fetch()) === true) {
				$extensions[] = ['name' => $row['extname'], 'version' => $row['extversion']];
			}
		} catch (Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()];
			$this->logger->warning(message: '[SettingsController] Failed to fetch PostgreSQL extensions', context: $context);
		}

		return $extensions;
	}//end postgresExtensions()
}//end class
