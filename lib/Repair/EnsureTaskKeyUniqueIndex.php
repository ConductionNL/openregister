<?php

/**
 * EnsureTaskKeyUniqueIndex: add the unique index on the task key that
 * migration Version1Date20261024120000 had to leave out.
 *
 * That migration skips the index when stored task keys already collide, and
 * Nextcloud never runs a migration twice, so on such an instance nothing
 * would ever add it. This post-migration step does: on every upgrade and on
 * `occ maintenance:repair` it counts the shared keys again and, once there
 * are none, adds the index. Idempotent: once the index is known to exist an
 * app-config flag stops the step from introspecting the database again.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Migration\Version1Date20261024120000;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds `or_tasks_key_uniq` once the stored task keys allow it.
 *
 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
 */
class EnsureTaskKeyUniqueIndex implements IRepairStep {

	/**
	 * App-config key set once the index is known to exist.
	 *
	 * @var string
	 */
	public const DONE_KEY = 'taskKeyUniqueIndex';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db        The database.
	 * @param IConfig         $config    Reads the table prefix.
	 * @param IAppConfig      $appConfig Holds the done flag.
	 * @param LoggerInterface $logger    The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Make the task key unique once no two tasks share one';
	}//end getName()

	/**
	 * Add the index when it is absent and no key is shared; warn when one is.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool('openregister', self::DONE_KEY, false) === true
			|| $this->db->tableExists(Version1Date20261024120000::TABLE) === false
		) {
			return;
		}

		try {
			$shared = $this->sharedKeys();
			if ($shared > 0) {
				$output->warning(
					$shared.' task keys are shared by more than one task, so task_key is not unique yet. '
					.'Give those tasks distinct keys, then run occ maintenance:repair to add the index.'
				);
				return;
			}

			$schema = $this->db->createSchema();
			$table  = $schema->getTable($this->config->getSystemValueString('dbtableprefix', 'oc_').Version1Date20261024120000::TABLE);
			if ($table->hasColumn('task_key') === false) {
				return;
			}

			if ($table->hasIndex(Version1Date20261024120000::INDEX) === false) {
				$table->addUniqueIndex(['task_key'], Version1Date20261024120000::INDEX);
				$this->db->migrateToSchema($schema);
				$output->info('task_key is now unique ('.Version1Date20261024120000::INDEX.')');
			}

			$this->appConfig->setValueBool('openregister', self::DONE_KEY, true);
		} catch (Throwable $e) {
			$this->logger->warning('[EnsureTaskKeyUniqueIndex] the task key index could not be added: '.$e->getMessage());
			$output->warning('task_key unique index not added: '.$e->getMessage());
		}//end try
	}//end run()

	/**
	 * The number of non-null keys carried by more than one task, counted as the migration counts them.
	 *
	 * @return int
	 */
	protected function sharedKeys(): int {
		return (new Version1Date20261024120000(db: $this->db))->sharedKeys();
	}//end sharedKeys()
}//end class
