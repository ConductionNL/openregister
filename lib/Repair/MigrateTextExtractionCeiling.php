<?php

/**
 * MigrateTextExtractionCeiling: states the text extraction ceiling as
 * `textExtraction.maxBytes` and drops the two keys it replaces.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: move `maxFileSizeMB` (mebibytes) into `textExtraction.maxBytes` (bytes).
 *
 * `maxFileSizeMB` and `maxFileSize` were stored and never read; the ceiling
 * now lives in `textExtraction.maxBytes`. A stored `maxFileSizeMB` of 100
 * becomes 104857600. A stored `textExtraction.maxBytes` is kept as it is.
 *
 * Idempotent: settings holding neither legacy key are not written, so a
 * second run changes nothing. Never throws: a failure is logged, and the
 * settings read keeps stating the legacy ceiling until the step runs again.
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */
class MigrateTextExtractionCeiling implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig The app configuration.
	 * @param LoggerInterface $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-1-1
	 */
	public function getName(): string {
		return 'State the text extraction ceiling in bytes (textExtraction.maxBytes)';
	}//end getName()

	/**
	 * Rewrite the stored file settings when they still hold a legacy ceiling key.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-1-1
	 */
	public function run(IOutput $output): void {
		try {
			$stored = json_decode($this->appConfig->getValueString(Application::APP_ID, 'fileManagement', ''), true);
			if (is_array($stored) === false || self::holdsLegacyKey(settings: $stored) === false) {
				return;
			}

			$migrated = FileSettingsHandler::withCeiling(fileSettings: $stored, source: $stored);
			$this->appConfig->setValueString(Application::APP_ID, 'fileManagement', json_encode($migrated));
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[MigrateTextExtractionCeiling] Could not migrate the text extraction ceiling',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return;
		}

		$output->info(sprintf('Text extraction ceiling is now %d bytes.', $migrated[FileSettingsHandler::TEXT_EXTRACTION_KEY]['maxBytes']));
	}//end run()

	/**
	 * Whether the settings hold one of the replaced keys.
	 *
	 * @param array<string, mixed> $settings The stored file settings.
	 *
	 * @return bool
	 */
	private static function holdsLegacyKey(array $settings): bool {
		foreach (FileSettingsHandler::LEGACY_CEILING_KEYS as $legacyKey) {
			if (array_key_exists($legacyKey, $settings) === true) {
				return true;
			}
		}

		return false;
	}//end holdsLegacyKey()
}//end class
