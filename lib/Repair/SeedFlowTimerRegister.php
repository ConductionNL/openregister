<?php

/**
 * SeedFlowTimerRegister: materialises the flow-timers register — the
 * `working-calendar` and `escalation-ladder` schemas with their seeded
 * defaults (`nl-national`, `example-organisation`, `nl-termijn-default`).
 *
 * OpenRegister does not self-import its own register JSON at boot (ADR-037).
 * This step decodes `lib/Settings/flow_timer_register.json` and calls
 * `ConfigurationService::importFromApp(force: false)` — NOT
 * `importFromFilePath()`, which rejects an absolute path — so the import is
 * idempotent: a re-run does not duplicate the calendar or overwrite an
 * administrator's edit to the ladder. Never throws — a failure logs a warning
 * and leaves the instance otherwise healthy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
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
 * Imports the flow-timers register descriptor idempotently on upgrade/install.
 */
class SeedFlowTimerRegister implements IRepairStep {
	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	public const REGISTER_PATH = '/lib/Settings/flow_timer_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	private const REGISTER_VERSION = '1.0.0';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationService $configurationService The OR configuration importer.
	 * @param IAppManager $appManager Resolves the openregister app path on disk.
	 * @param LoggerInterface $logger Logger for import diagnostics.
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
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function getName(): string {
		return 'Seed the flow-timers register (working calendars + escalation ladders)';
	}//end getName()

	/**
	 * Run the repair step, importing the flow-timers register descriptor.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function run(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('Flow-timers register descriptor not found: ' . $path);
				return;
			}

			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Flow-timers register descriptor is not valid JSON: ' . $path);
				return;
			}

			// importFromApp() takes the DECODED descriptor; force: false keeps
			// the import idempotent and leaves administrator edits in place.
			$this->configurationService->importFromApp(
				appId: 'openregister',
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Flow-timers register imported (working-calendar + escalation-ladder schemas and seeded defaults)');
		} catch (Throwable $e) {
			$this->logger->warning('[SeedFlowTimerRegister] import failed: ' . $e->getMessage());
			$output->warning('Flow-timers register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
