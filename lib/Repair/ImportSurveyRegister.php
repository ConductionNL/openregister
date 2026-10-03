<?php

/**
 * ImportSurveyRegister — materialises the survey data model.
 *
 * A survey is four schemas and a register, and without this step none of them
 * exist on an instance: the descriptor would sit in the source tree while
 * every survey endpoint answered about a register that was never imported.
 * This step imports `lib/Settings/survey_register.json` through
 * `ConfigurationService::importFromApp()` so the `surveys` register and its
 * four schemas land idempotently on install and on `occ upgrade`, matched by
 * slug and gated on the descriptor version — the same convention as
 * {@see ImportFlowRegister}.
 *
 * Bump `components.registers.surveys.version` when a schema changes, or an
 * upgraded instance keeps the old shape: the importer short-circuits when the
 * shipped version is not higher than the installed one, and says nothing
 * about it.
 *
 * Never throws. A failure logs a warning and leaves the instance otherwise
 * healthy.
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
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-001-a-survey-is-its-own-object-with-its-own-questions
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
 * Imports the survey register descriptor idempotently on upgrade/install.
 */
class ImportSurveyRegister implements IRepairStep {
	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	private const REGISTER_PATH = '/lib/Settings/survey_register.json';

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
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-001-a-survey-is-its-own-object-with-its-own-questions
	 */
	public function getName(): string {
		return 'Import OpenRegister survey register (surveys register + its four schemas)';
	}//end getName()

	/**
	 * Run the repair step, importing the survey register descriptor.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-001-a-survey-is-its-own-object-with-its-own-questions
	 */
	public function run(IOutput $output): void {
		try {
			$path = $this->appManager->getAppPath('openregister') . self::REGISTER_PATH;
			if (is_file($path) === false) {
				$output->warning('Survey register descriptor not found: ' . $path);
				return;
			}

			// Import the DECODED descriptor via importFromApp() — not
			// importFromFilePath(), which expects a Nextcloud-root-relative path
			// and would fail closed on this absolute one.
			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				$output->warning('Survey register descriptor is not valid JSON: ' . $path);
				return;
			}

			$this->configurationService->importFromApp(
				appId: 'openregister',
				data: $data,
				version: self::REGISTER_VERSION,
				force: false
			);

			$output->info('Survey register imported (surveys register + survey, surveyQuestion, surveyInvitation and surveyAnswerSet)');
		} catch (Throwable $e) {
			$this->logger->warning('[ImportSurveyRegister] import failed: ' . $e->getMessage());
			$output->warning('Survey register import skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
