<?php

/**
 * ImportDismissedPairRegister — materialises the register dismissals are recorded in.
 *
 * `dismissed_pair_register.json` is a `type: core` descriptor declaring the
 * `dismissed-pair` register and its `dismissedPair` schema. Per ADR-005 Rule 1
 * shipping the JSON alone does nothing at runtime: without a Repair step the
 * register never appears on any instance.
 *
 * 🔴 THIS ONE IS NOT COSMETIC, and its failure mode is the quiet kind.
 * `DuplicateDetectionService` asks this register which pairs a person has
 * already ruled out, and a register that does not exist answers "none". The
 * scorer then keeps offering every dismissed pair, the dismissal button
 * appears to do nothing, and nothing anywhere reports an error — which is
 * precisely the failure the capability exists to prevent, reintroduced one
 * layer down.
 *
 * Modelled on {@see ImportMergeOperationRegister}, which records the same
 * lesson: every descriptor with a Repair step was present on the instance and
 * every descriptor without one was ABSENT, and the correlation was exact.
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
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
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
 * Imports the dismissed-pair register descriptor idempotently.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportDismissedPairRegister implements IRepairStep {
	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	private const REGISTER_PATH = '/lib/Settings/dismissed_pair_register.json';

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
	private const CONFIG_APP_ID = 'openregister.dismissed-pair';

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
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function getName(): string {
		return 'Import OpenRegister dismissed-pair register (pairs reviewed as not duplicates)';
	}//end getName()

	/**
	 * Run the repair step, importing the dismissed-pair register descriptor.
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
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function run(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('Dismissed-pair register descriptor not found: ' . $path);
				return;
			}

			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Dismissed-pair register descriptor is not valid JSON: ' . $path);
				return;
			}

			$this->configurationService->importFromApp(
				appId: self::CONFIG_APP_ID,
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Dismissed-pair register imported (dismissed-pair + dismissedPair)');
		} catch (Throwable $e) {
			$this->logger->warning('[ImportDismissedPairRegister] import failed: ' . $e->getMessage());
			$output->warning('Dismissed-pair register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
