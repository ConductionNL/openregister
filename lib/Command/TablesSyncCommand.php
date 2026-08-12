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

use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
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
	 * @param TablesTableReader $reader Guarded gateway to Tables services.
	 * @param TablesSchemaSyncService $syncService Schema reconcile logic.
	 * @param IGroupManager $groupManager Admin-user enumeration.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TablesTableReader $reader,
		private readonly TablesSchemaSyncService $syncService,
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

		$userOption = $input->getOption('user');
		$userIds = $this->adminUserIds();
		if ($userOption !== null && $userOption !== '') {
			$userIds = [(string)$userOption];
		}

		if (empty($userIds) === true) {
			$output->writeln('<comment>No user to enumerate Tables for (pass --user=UID).</comment>');
			return Command::SUCCESS;
		}

		$tables = $this->reader->collectTableDescriptors(userIds: $userIds);
		$stats = $this->syncService->reconcile(tables: $tables);

		$output->writeln(
			sprintf(
				'<info>Reconciled the "tables" register: seeded=%d, retired=%d, skipped=%d (from %d table(s)).</info>',
				$stats['seeded'],
				$stats['retired'],
				$stats['skipped'],
				count($tables)
			)
		);

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Resolve the instance's admin user ids for table enumeration.
	 *
	 * @return array<int, string> The admin user ids (may be empty).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function adminUserIds(): array {
		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return [];
		}

		$ids = [];
		foreach ($admins->getUsers() as $user) {
			$ids[] = $user->getUID();
		}

		return $ids;
	}//end adminUserIds()
}//end class
