<?php

/**
 * ImportMergeOperationRegister — materialises the register merges are recorded in.
 *
 * `merge_operation_register.json` is a `type: core` descriptor declaring the
 * `merge-operation` register and its `mergeOperation` schema, and it shipped
 * with no Repair step to import it. Per ADR-005 Rule 1 that means it never
 * appeared on any instance: "shipping the JSON alone does nothing at runtime."
 *
 * 🔴 THIS ONE IS NOT COSMETIC. `MergeService::merge()` writes the audit row for
 * every merge straight into it:
 *
 *     $this->objectService->saveObject(
 *         object:   $mergeOperation,
 *         register: self::MERGE_REGISTER,   // 'merge-operation'
 *         schema:   self::MERGE_SCHEMA      // 'mergeOperation'
 *     );
 *
 * and `reverseMerge` reads the `preMergeSnapshot` back out of that row to undo
 * a merge. A register that does not exist is a merge history that does not
 * exist, and reversibility that cannot be exercised.
 *
 * FOUND BY `occ openregister:descriptors:list`, which reported `merge-operation`
 * ABSENT alongside six others. The correlation across the app's fourteen
 * descriptors was exact: every one with a Repair step was present, every one
 * without was missing. This was the only non-mock descriptor in the second
 * group — the outlier that named the defect.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports the merge-operation register descriptor idempotently.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportMergeOperationRegister implements IRepairStep {
	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	private const REGISTER_PATH = '/lib/Settings/merge_operation_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	private const REGISTER_VERSION = '1.0.0';

	/**
	 * Configuration identity for this descriptor.
	 *
	 * Its own app id, so the `importFromApp` version gate moves independently of
	 * the other system registers — a bump here must not be masked by one of
	 * theirs, and vice versa.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = 'openregister.merge-operation';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationService $configurationService The OR configuration importer.
	 * @param IAppManager          $appManager           Resolves the openregister app path on disk.
	 * @param LoggerInterface      $logger               Logger for import diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConfigurationService $configurationService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister merge-operation register (merge audit + reversal snapshots)';
	}//end getName()

	/**
	 * Run the repair step, importing the merge-operation register descriptor.
	 *
	 * Never throws — a failure logs a warning and leaves the instance otherwise
	 * healthy, matching every sibling importer. That is the right trade at boot,
	 * where the alternative is an app that will not install; the visibility cost
	 * is paid by `occ openregister:descriptors:list`, which reports the register
	 * as ABSENT rather than leaving the failure only in a log.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('Merge-operation register descriptor not found: ' . $path);
				return;
			}

			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Merge-operation register descriptor is not valid JSON: ' . $path);
				return;
			}

			$this->configurationService->importFromApp(
				appId: self::CONFIG_APP_ID,
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Merge-operation register imported (merge-operation + mergeOperation)');
		} catch (Throwable $e) {
			$this->logger->warning('[ImportMergeOperationRegister] import failed: ' . $e->getMessage());
			$output->warning('Merge-operation register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
