<?php

/**
 * OpenRegister tables:sync command.
 *
 * Reconciles the `tables` virtual register on demand — the admin's tool for
 * picking up newly created Tables tables and column changes between upgrades
 * (Nextcloud Tables emits no table-created or column-changed event, so new
 * tables do not appear live). It enumerates the tables visible to the instance's
 * admin users (or to a `--user` you name) via {@see TablesTableReader} and hands
 * them to {@see TablesSchemaSyncService::reconcile()}, which seeds one schema per
 * table, refreshes managed schemas, and retires schemas whose table is gone.
 * Idempotent; a no-op when the Tables app is absent.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Repair\AdminUserIds;
use OCA\OpenRegister\Service\ObjectSource\TablesCatalogueInterface;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaReconcilerInterface;
use OCP\IGroupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconcile the `tables` virtual register with the Nextcloud Tables app.
 */
class TablesSyncCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param TablesCatalogueInterface $reader Guarded gateway to Tables services.
	 * @param TablesSchemaReconcilerInterface $syncService Schema reconcile logic.
	 * @param IGroupManager $groupManager Admin-user enumeration.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TablesCatalogueInterface $reader,
		private readonly TablesSchemaReconcilerInterface $syncService,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:tables:sync')
			->setDescription(
				'Reconcile the "tables" virtual register with the Nextcloud Tables app '
				. '(seed a schema per table, refresh columns, retire deleted tables).'
			)
			->addOption(
				'user',
				null,
				InputOption::VALUE_REQUIRED,
				'Enumerate the tables visible to this user id instead of the admin users.'
			);
	}//end configure()

	/**
	 * Run the reconcile.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->reader->isAvailable() === false) {
			$output->writeln('<comment>Nextcloud Tables app is not enabled — nothing to sync.</comment>');
			return Command::SUCCESS;
		}

		$userIds = $this->userIds(input: $input);
		if (empty($userIds) === true) {
			$output->writeln('<comment>No user to enumerate Tables for (pass --user=UID).</comment>');
			return Command::SUCCESS;
		}

		$tables = $this->reader->collectTableDescriptors(userIds: $userIds);
		$stats = $this->syncService->reconcile(tables: $tables);
		$output->writeln(self::describeReconcile(stats: $stats, tableCount: count($tables)));

		return Command::SUCCESS;
	}//end execute()

	/**
	 * The users to enumerate tables for: the --user, or else the admin users.
	 *
	 * The admin users are looked up either way.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return array<int, string> The user ids (may be empty).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function userIds(InputInterface $input): array {
		$userOption = $input->getOption('user');
		$userIds = AdminUserIds::of(groupManager: $this->groupManager);
		if ($userOption !== null && $userOption !== '') {
			$userIds = [(string)$userOption];
		}

		return $userIds;
	}//end userIds()

	/**
	 * The closing line: the reconcile's counts and the number of tables it saw.
	 *
	 * @param array{seeded: int, retired: int, skipped: int} $stats      The reconcile's counts.
	 * @param int                                            $tableCount The tables enumerated.
	 *
	 * @return string The line to print.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private static function describeReconcile(array $stats, int $tableCount): string {
		return sprintf(
			'<info>Reconciled the "tables" register: seeded=%d, retired=%d, skipped=%d (from %d table(s)).</info>',
			$stats['seeded'],
			$stats['retired'],
			$stats['skipped'],
			$tableCount
		);
	}//end describeReconcile()
}//end class
