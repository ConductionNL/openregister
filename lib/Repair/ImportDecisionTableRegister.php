<?php

/**
 * ImportDecisionTableRegister: materialises the register named decision tables live in.
 *
 * `lib/Settings/decision_table_register.json` declares the `decision-tables`
 * register and its `decision-table` schema. Shipping the JSON alone does
 * nothing at runtime (ADR-005 Rule 1): without this step the register never
 * exists, every `tableRef` on a flow step names nothing, and the step is
 * refused at save with "no decision table has the key".
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
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIds;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports the decision-table register descriptor idempotently.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportDecisionTableRegister implements IRepairStep {
	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	private const REGISTER_PATH = '/lib/Settings/decision_table_register.json';

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
	private const CONFIG_APP_ID = DescriptorConfigIds::DECISION_TABLES;

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
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister decision-table register (named decision tables flows reference)';
	}//end getName()

	/**
	 * Run the repair step, importing the decision-table register descriptor.
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
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('Decision-table register descriptor not found: ' . $path);
				return;
			}

			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Decision-table register descriptor is not valid JSON: ' . $path);
				return;
			}

			$this->configurationService->importFromApp(
				appId: self::CONFIG_APP_ID,
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Decision-table register imported (decision-tables + decision-table)');
		} catch (Throwable $e) {
			$this->logger->warning('[ImportDecisionTableRegister] import failed: ' . $e->getMessage());
			$output->warning('Decision-table register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
