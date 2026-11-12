<?php

/**
 * One batch run of the blob-to-magic-table migration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of one migration batch, its start time and its counters.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class BlobMigrationRun {

	/**
	 * App config key prefix for migration progress tracking.
	 */
	private const CONFIG_PREFIX = 'blob_migration_';

	/**
	 * Start of the run, in seconds.
	 *
	 * @var float
	 */
	public readonly float $startTime;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	public readonly LoggerInterface $logger;

	/**
	 * Database connection holding the blob table.
	 *
	 * @var IDBConnection
	 */
	public readonly IDBConnection $db;

	/**
	 * App config holding the migration progress.
	 *
	 * @var IAppConfig
	 */
	public readonly IAppConfig $appConfig;

	/**
	 * Register mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Magic mapper the rows move to.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $magicMapper;

	/**
	 * Rows saved to a magic table in this run.
	 *
	 * @var integer
	 */
	public int $migrated = 0;

	/**
	 * Rows left in the blob table in this run.
	 *
	 * @var integer
	 */
	public int $skipped = 0;

	/**
	 * Constructor
	 *
	 * @param ContainerInterface $container App container the collaborators are resolved from
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(ContainerInterface $container) {
		$this->startTime      = microtime(true);
		$this->logger         = $container->get(LoggerInterface::class);
		$this->db             = $container->get(IDBConnection::class);
		$this->appConfig      = $container->get(IAppConfig::class);
		$this->registerMapper = $container->get(RegisterMapperInterface::class);
		$this->schemaMapper   = $container->get(SchemaMapperInterface::class);
		$this->magicMapper    = $container->get(MagicMapperInterface::class);
	}//end __construct()

	/**
	 * Read a migration progress value.
	 *
	 * @param string $key     Key after the prefix
	 * @param string $default Value when unset
	 *
	 * @return string
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function config(string $key, string $default): string {
		return $this->appConfig->getValueString(app: 'openregister', key: self::CONFIG_PREFIX.$key, default: $default);
	}//end config()

	/**
	 * Write a migration progress value.
	 *
	 * @param string $key   Key after the prefix
	 * @param string $value Value
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function setConfig(string $key, string $value): void {
		$this->appConfig->setValueString(app: 'openregister', key: self::CONFIG_PREFIX.$key, value: $value);
	}//end setConfig()
}//end class
