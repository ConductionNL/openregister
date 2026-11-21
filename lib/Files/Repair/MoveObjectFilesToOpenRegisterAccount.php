<?php

/**
 * MoveObjectFilesToOpenRegisterAccount: object files leave people's homes.
 *
 * Before object-files-follow-object-access an object's folder was made in the
 * home of whoever saved first, so the object's other readers could not reach
 * its files. This step moves every managed folder into the openregister
 * account's home, keeping file ids, on local storage only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Repair;

use OCA\OpenRegister\Files\Service\File\ObjectFileMigration;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: move object files into the openregister account's home.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class MoveObjectFilesToOpenRegisterAccount implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container; the migration is resolved lazily,
	 *                                      so a half-wired boot skips instead of failing.
	 * @param LoggerInterface    $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by occ.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getName(): string {
		return 'Move object files into the OpenRegister account';
	}//end getName()

	/**
	 * Run the move and report the tally. It never throws.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$tally = $this->container->get(ObjectFileMigration::class)->run();
		} catch (Throwable $e) {
			$this->reportSkipped(output: $output, error: $e);
			return;
		}

		if ($tally['refused'] === true) {
			$output->warning(ObjectFileMigration::REFUSAL_MESSAGE);
		}

		$output->info('Object files: ' . (new ObjectFileMoveCounts())->sentence(tally: $tally));
	}//end run()

	/**
	 * Log and report a move that could not run.
	 *
	 * @param IOutput   $output The repair output.
	 * @param Throwable $error  Why the move could not run.
	 *
	 * @return void
	 */
	private function reportSkipped(IOutput $output, Throwable $error): void {
		$this->logger->warning(
			message: '[MoveObjectFilesToOpenRegisterAccount] Skipped: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$output->warning('Object files: not moved (' . $error->getMessage() . ')');
	}//end reportSkipped()
}//end class
