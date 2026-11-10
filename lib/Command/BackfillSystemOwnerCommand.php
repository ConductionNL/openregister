<?php

/**
 * OpenRegister backfill-system-owner command
 *
 * One-shot data backfill that updates magic-table rows with an empty
 * `_owner` (legacy rows imported / created before #1645) to the
 * `__system__` sentinel so admin RBAC filtering surfaces them. Idempotent
 * by design: re-running on already-backfilled tables updates 0 rows.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;

/**
 * Backfill the `__system__` sentinel on magic-table rows with empty `_owner`.
 */
class BackfillSystemOwnerCommand extends MagicTableCommand {
	/**
	 * Wire the table selection and database connection used by the command.
	 *
	 * @param MagicTableSelection $selection Finds the tables to work on.
	 * @param IDBConnection $db Database connection for native UPDATE.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function __construct(
		MagicTableSelection $selection,
		private readonly IDBConnection $db,
	) {
		parent::__construct(selection: $selection);
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:backfill-system-owner')
			->setDescription(
				'Backfill _owner=\'__system__\' on magic-table rows with empty _owner (legacy rows from before #1645).'
			);
		$this->addSelectionOptions();
	}//end configure()

	/**
	 * Say what this run is about to do.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function announce(MagicTableRun $run): void {
		$run->output->writeln(
			sprintf(
				'<info>Backfilling _owner=\'%s\' on rows with _owner=\'\'%s</info>',
				OrganisationService::SYSTEM_USER_ID_DEFAULT,
				$run->dryRunNote(words: ' (dry run)')
			)
		);
	}//end announce()

	/**
	 * Backfill one table and report it.
	 *
	 * @param MagicTableTarget $target The table
	 * @param MagicTableRun $run The run
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function workOn(MagicTableTarget $target, MagicTableRun $run): void {
		$scanned = $this->countEmptyOwners(tableName: $target->tableName);
		$updated = 0;
		if ($scanned !== 0 && $run->isDryRun() === false) {
			$updated = $this->setSystemOwner(tableName: $target->tableName);
		}

		$run->scanned += $scanned;
		$run->changed += $updated;
		$run->output->writeln(sprintf('  %s: scanned=%d updated=%d', $target->label(), $scanned, $updated));
	}//end workOn()

	/**
	 * Report the totals.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function finish(MagicTableRun $run): int {
		$run->output->writeln(
			sprintf(
				'<info>Done. Tables=%d scanned=%d updated=%d%s</info>',
				$run->tables,
				$run->scanned,
				$run->changed,
				$run->dryRunNote(words: ' (dry run — no writes performed)')
			)
		);

		return Command::SUCCESS;
	}//end finish()

	/**
	 * Count the rows with empty `_owner` in a magic table.
	 *
	 * @param string $tableName Fully qualified magic table name (without `oc_` prefix).
	 *
	 * @return int The rows
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function countEmptyOwners(string $tableName): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($tableName)
			->where($qb->expr()->eq('_owner', $qb->createNamedParameter('')));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countEmptyOwners()

	/**
	 * Set the system owner on the rows with empty `_owner`.
	 *
	 * @param string $tableName Fully qualified magic table name (without `oc_` prefix).
	 *
	 * @return int The rows updated
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function setSystemOwner(string $tableName): int {
		$update = $this->db->getQueryBuilder();
		$update->update($tableName)
			->set('_owner', $update->createNamedParameter(OrganisationService::SYSTEM_USER_ID_DEFAULT))
			->where($update->expr()->eq('_owner', $update->createNamedParameter('')));

		return (int)$update->executeStatement();
	}//end setSystemOwner()
}//end class
