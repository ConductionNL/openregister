<?php

/**
 * OpenRegister magic table sweep run
 *
 * One pass of a repair step over every magic object table: the list of
 * tables, the tally of tables changed and failed, and the closing line.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;

/**
 * A repair step's pass over the magic tables, counted as it goes.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
 */
final class MagicTableSweepRun {

	/**
	 * The tables changed by the pass.
	 *
	 * @var integer
	 */
	public int $changed = 0;

	/**
	 * The tables the pass could not change.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * The tables the pass covers.
	 *
	 * @var string[]
	 */
	private array $tables = [];

	/**
	 * Start a pass for one repair step.
	 *
	 * @param string  $step   The step's short class name, as its log lines are tagged.
	 * @param IOutput $output The migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
	 */
	public function __construct(
		public readonly string $step,
		public readonly IOutput $output,
	) {
	}//end __construct()

	/**
	 * List the magic tables, or log why they cannot be listed.
	 *
	 * A database that cannot list its tables during an upgrade is not
	 * something a repair step can fix, and it must not stop the upgrade.
	 *
	 * @param MagicMapperInterface $magicMapper Magic-table discovery.
	 * @param LoggerInterface      $logger      Logger.
	 *
	 * @return string[]|null The table names, or null when the step must skip.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
	 */
	public function listTables(MagicMapperInterface $magicMapper, LoggerInterface $logger): ?array {
		try {
			$this->tables = $magicMapper->listMagicTableNames();
		} catch (\Throwable $e) {
			$logger->info('[' . $this->step . '] Could not list magic tables, skipping: ' . $e->getMessage());
			return null;
		}

		return $this->tables;
	}//end listTables()

	/**
	 * Print the closing line: how many tables were checked, changed and not changed.
	 *
	 * @param string $checkedFor What each table was checked for.
	 * @param string $changedAs  The word for a changed table.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
	 */
	public function report(string $checkedFor, string $changedAs): void {
		$this->output->info(
			sprintf(
				'Checked %d object table(s) for %s: %d %s, %d could not be.',
				count($this->tables),
				$checkedFor,
				$this->changed,
				$changedAs,
				$this->failed
			)
		);
	}//end report()
}//end class
