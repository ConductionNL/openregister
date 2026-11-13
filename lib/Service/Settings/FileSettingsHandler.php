<?php

/**
 * OpenRegister File Settings Handler
 *
 * This file contains the handler class for managing file management configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Exception\ConfidenceThresholdOutOfRangeException;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\File\ArchiveBounds;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Handler for file management settings operations.
 *
 * This handler is responsible for managing file processing, vectorization,
 * and text extraction configuration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */
class FileSettingsHandler {

	/**
	 * App config key holding the OpenAnonymiser password.
	 *
	 * Stored on its own key, marked sensitive, and never inside the
	 * `fileManagement` blob: that blob is what the settings screen reads
	 * back, so a password in it would be returned in plain text.
	 *
	 * @var string
	 */
	public const OPENANONYMISER_CREDENTIAL_FIELD = 'openAnonymiserPassword';

	/**
	 * Former name of OPENANONYMISER_CREDENTIAL_FIELD, kept for existing callers.
	 *
	 * @deprecated Use OPENANONYMISER_CREDENTIAL_FIELD.
	 *
	 * @var string
	 */
	public const OPENANONYMISER_PASSWORD_KEY = self::OPENANONYMISER_CREDENTIAL_FIELD;

	/**
	 * The minimum confidence a detection needs when no organisation setting says otherwise.
	 *
	 * @var float
	 */
	public const DEFAULT_CONFIDENCE_THRESHOLD = 0.5;

	/**
	 * The file settings key holding the anonymisation settings.
	 *
	 * @var string
	 */
	public const ANONYMISATION_KEY = 'anonymisation';

	/**
	 * The file settings returned when none are stored, and the default of every key on update.
	 *
	 * The enabled file types are LLPhant-friendly: native PHP support plus common
	 * library-based formats. extractionScope is none, all, folders or objects;
	 * textExtractor llphant or dolphin; extractionMode background, immediate or
	 * manual. openAnonymiserSource is internal (the AppAPI ExApp) or external (an
	 * operator URL); an empty openAnonymiserUsername sends no credentials.
	 * entityRecognitionMethod is regex, presidio, openanonymiser, llm or hybrid,
	 * or auto: an internal "not yet configured" marker resolved at state-query
	 * time to the recommended backend, never shown as a UI option.
	 * openAnonymiserPasswordSet is filled in on every read.
	 *
	 * @var array<string, mixed>
	 */
	private const FILE_DEFAULTS = [
		'vectorizationEnabled' => false,
		'provider' => null,
		'chunkingStrategy' => 'RECURSIVE_CHARACTER',
		'chunkSize' => 1000,
		'chunkOverlap' => 200,
		'enabledFileTypes' => ['txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf', 'docx', 'doc', 'xlsx', 'xls'],
		'ocrEnabled' => false,
		'maxFileSizeMB' => 100,
		'extractionScope' => 'objects',
		'textExtractor' => 'llphant',
		'extractionMode' => 'background',
		'maxFileSize' => 100,
		'batchSize' => 10,
		'dolphinApiEndpoint' => '',
		'dolphinApiKey' => '',
		'presidioApiEndpoint' => '',
		'openAnonymiserApiEndpoint' => '',
		'openAnonymiserSource' => 'internal',
		'openAnonymiserUsername' => '',
		'openAnonymiserPasswordSet' => false,
		'entityRecognitionEnabled' => false,
		'entityRecognitionMethod' => 'auto',
		self::ANONYMISATION_KEY => ['confidenceThreshold' => self::DEFAULT_CONFIDENCE_THRESHOLD],
		ArchiveBounds::SETTINGS_KEY => ArchiveBounds::DEFAULTS,
	];

	/**
	 * Constructor for FileSettingsHandler
	 *
	 * The app id comes from Application::APP_ID (ADR-011 Rule 4).
	 *
	 * @param IAppConfig $appConfig Configuration service.
	 * @param OwnSettingsChangeRecorder|null $changeRecorder Records every save on the audit trail (openregister#4100).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ?OwnSettingsChangeRecorder $changeRecorder = null,
	) {
	}//end __construct()

	/**
	 * Get File Management settings only.
	 *
	 * @return array File management configuration.
	 *
	 * @throws \RuntimeException If File Management settings retrieval fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 */
	public function getFileSettingsOnly(): array {
		try {
			$fileConfig = $this->appConfig->getValueString(Application::APP_ID, 'fileManagement', '');
			if (empty($fileConfig) === true) {
				$defaults = self::FILE_DEFAULTS;
				$defaults['openAnonymiserPasswordSet'] = $this->hasOpenAnonymiserPassword();
				return $defaults;
			}

			return $this->readableFileSettings(fileSettings: json_decode($fileConfig, true));
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve File Management settings: ' . $e->getMessage());
		}//end try
	}//end getFileSettingsOnly()

	/**
	 * Stored file settings as a read returns them.
	 *
	 * The same batch size bound as updateFileSettings() applies on the way out.
	 * Values stored before that clamp existed are still in appconfig (the
	 * previous write path accepted 0, negatives and anything above 500) and the
	 * cron job hands batchSize straight to extractPendingFiles(), so a bound that
	 * only guards the write path leaves those installations unprotected. The
	 * password is never part of a settings read, only whether one is set.
	 *
	 * @param mixed $fileSettings The decoded stored settings; anything but an array is returned as it is.
	 *
	 * @return mixed
	 */
	private function readableFileSettings($fileSettings) {
		if (is_array($fileSettings) === false) {
			return $fileSettings;
		}

		if (array_key_exists('batchSize', $fileSettings) === true) {
			$fileSettings['batchSize'] = $this->boundedBatchSize(batchSize: $fileSettings['batchSize']);
		}

		// The archive bounds always read whole: an install saved before they
		// existed still states the bounds archives are opened under.
		$fileSettings[ArchiveBounds::SETTINGS_KEY] = ArchiveBounds::fromSettings(fileSettings: $fileSettings)->toArray();

		unset($fileSettings[self::OPENANONYMISER_CREDENTIAL_FIELD]);
		$fileSettings[self::ANONYMISATION_KEY] = $this->readableAnonymisation(stored: ($fileSettings[self::ANONYMISATION_KEY] ?? null));
		$fileSettings['openAnonymiserUsername'] = (string) ($fileSettings['openAnonymiserUsername'] ?? '');
		$fileSettings['openAnonymiserPasswordSet'] = $this->hasOpenAnonymiserPassword();

		return $fileSettings;
	}//end readableFileSettings()

	/**
	 * A batch size between 1 and 500.
	 *
	 * A zero or negative batch size makes the cron job extract nothing and report
	 * "no pending files" for a queue that is not empty, and an unbounded one lets
	 * a single tick attempt MAX_PENDING_WINDOWS x batchSize files.
	 *
	 * @param mixed $batchSize The submitted or stored batch size.
	 *
	 * @return int
	 */
	private function boundedBatchSize($batchSize): int {
		return max(1, min((int) $batchSize, 500));
	}//end boundedBatchSize()

	/**
	 * Update File Management settings only.
	 *
	 * @param array $fileData File management configuration data.
	 *
	 * @return (false|int|mixed|null|string[])[] Updated file management configuration.
	 *
	 * @throws \RuntimeException If File Management settings update fails.
	 *
	 * @psalm-return array{vectorizationEnabled: false|mixed, provider: mixed|null,
	 *     chunkingStrategy: 'RECURSIVE_CHARACTER'|mixed, chunkSize: 1000|mixed,
	 *     chunkOverlap: 200|mixed,
	 *     enabledFileTypes: list{'txt', 'md', 'html', 'json', 'xml', 'csv', 'pdf',
	 *     'docx', 'doc', 'xlsx', 'xls'}|mixed, ocrEnabled: false|mixed,
	 *     maxFileSizeMB: 100|mixed, extractionScope: 'objects'|mixed,
	 *     textExtractor: 'llphant'|mixed, extractionMode: 'background'|mixed,
	 *     maxFileSize: 100|mixed, batchSize: 10|mixed,
	 *     dolphinApiEndpoint: ''|mixed, dolphinApiKey: ''|mixed}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 */
	public function updateFileSettingsOnly(array $fileData): array {
		try {
			$fileConfig = $this->submittedFileSettings(fileData: $fileData);
			$this->storeRecorded(fileConfig: $fileConfig, fileData: $fileData);

			$fileConfig['openAnonymiserPasswordSet'] = $this->hasOpenAnonymiserPassword();

			return $fileConfig;
		} catch (ConfidenceThresholdOutOfRangeException $e) {
			// The caller's value to correct, kept recognisable so the controller answers 400.
			throw $e;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update File Management settings: ' . $e->getMessage());
		}//end try
	}//end updateFileSettingsOnly()

	/**
	 * Store the file settings and the anonymiser password, recording the change.
	 *
	 * The snapshot is taken before either write and the change is recorded after
	 * both, so one audit entry covers the whole save.
	 *
	 * @param array<string, mixed> $fileConfig The file settings to store.
	 * @param array<string, mixed> $fileData   The settings being written, for the password.
	 *
	 * @return void
	 */
	private function storeRecorded(array $fileConfig, array $fileData): void {
		$auditKeys = ['fileManagement', OwnSettingsChangeRecorder::PLAIN_PREFIX . self::OPENANONYMISER_CREDENTIAL_FIELD];
		$before = $this->changeRecorder?->snapshot(keys: $auditKeys);
		$this->appConfig->setValueString(Application::APP_ID, 'fileManagement', json_encode($fileConfig));
		$this->storeOpenAnonymiserPassword(fileData: $fileData);
		if ($before !== null) {
			$this->changeRecorder?->record(before: $before, keys: $auditKeys);
		}
	}//end storeRecorded()

	/**
	 * The file settings to store: every default key from the submitted values or
	 * its default, the batch size bounded, the user name trimmed, and the review
	 * default (who owns a redaction decision on a file that belongs to no schema).
	 *
	 * @param array<string, mixed> $fileData The submitted settings.
	 *
	 * @return array<string, mixed>
	 */
	private function submittedFileSettings(array $fileData): array {
		$fileConfig = [];
		foreach (self::FILE_DEFAULTS as $key => $default) {
			$fileConfig[$key] = $fileData[$key] ?? $default;
		}

		unset($fileConfig['openAnonymiserPasswordSet']);
		$fileConfig['batchSize'] = $this->boundedBatchSize(batchSize: $fileConfig['batchSize']);
		$fileConfig[ArchiveBounds::SETTINGS_KEY] = ArchiveBounds::fromSettings(fileSettings: $fileConfig)->toArray();
		$fileConfig['openAnonymiserUsername'] = trim((string) $fileConfig['openAnonymiserUsername']);
		$fileConfig[ReviewPolicy::SETTINGS_KEY] = $this->reviewDefault(fileData: $fileData);
		$fileConfig[self::ANONYMISATION_KEY] = $this->submittedAnonymisation(fileData: $fileData);

		return $fileConfig;
	}//end submittedFileSettings()

	/**
	 * The minimum confidence a detection needs, from the organisation's file settings.
	 *
	 * Read at request time by every detection path, so a changed setting applies
	 * to the next detection without a restart.
	 *
	 * @return float A number between 0 and 1; the default when none is stored.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-confidence-threshold-is-an-organisation-setting-req-adi-007
	 */
	public function confidenceThreshold(): float {
		$stored = $this->appConfig->getValueString(Application::APP_ID, 'fileManagement', '');
		$settings = json_decode($stored, true);
		if (is_array($settings) === false) {
			return self::DEFAULT_CONFIDENCE_THRESHOLD;
		}

		return $this->readableAnonymisation(stored: ($settings[self::ANONYMISATION_KEY] ?? null))['confidenceThreshold'];
	}//end confidenceThreshold()

	/**
	 * The anonymisation settings as a read returns them: a stored threshold
	 * between 0 and 1, else the default.
	 *
	 * @param mixed $stored The stored anonymisation settings.
	 *
	 * @return array<string, mixed> The settings, with confidenceThreshold a float.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-confidence-threshold-is-an-organisation-setting-req-adi-007
	 */
	private function readableAnonymisation(mixed $stored): array {
		$settings = [];
		if (is_array($stored) === true) {
			$settings = $stored;
		}

		$threshold = ($settings['confidenceThreshold'] ?? null);
		$settings['confidenceThreshold'] = self::DEFAULT_CONFIDENCE_THRESHOLD;
		if (self::isThreshold(value: $threshold) === true) {
			$settings['confidenceThreshold'] = (float) $threshold;
		}

		return $settings;
	}//end readableAnonymisation()

	/**
	 * The anonymisation settings to store from a save: the submitted ones, or the
	 * stored ones when the save does not name them.
	 *
	 * @param array<string, mixed> $fileData The settings being saved.
	 *
	 * @return array<string, mixed> The settings to store.
	 *
	 * @throws ConfidenceThresholdOutOfRangeException When the threshold is not a number between 0 and 1.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-confidence-threshold-is-an-organisation-setting-req-adi-007
	 */
	private function submittedAnonymisation(array $fileData): array {
		$submitted = ($fileData[self::ANONYMISATION_KEY] ?? null);
		if (is_array($submitted) === false) {
			return ['confidenceThreshold' => $this->confidenceThreshold()];
		}

		if (array_key_exists('confidenceThreshold', $submitted) === false) {
			$submitted['confidenceThreshold'] = $this->confidenceThreshold();
		}

		if (self::isThreshold(value: $submitted['confidenceThreshold']) === false) {
			throw new ConfidenceThresholdOutOfRangeException(message: 'anonymisation.confidenceThreshold must be a number between 0 and 1');
		}

		$submitted['confidenceThreshold'] = (float) $submitted['confidenceThreshold'];

		return $submitted;
	}//end submittedAnonymisation()

	/**
	 * Whether a value is a number between 0 and 1, bounds included.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isThreshold(mixed $value): bool {
		if (is_int($value) === false && is_float($value) === false && (is_string($value) === false || is_numeric($value) === false)) {
			return false;
		}

		$number = (float) $value;

		return $number >= 0.0 && $number <= 1.0;
	}//end isThreshold()

	/**
	 * The instance review default from a settings save, refused when unsound.
	 *
	 * A save without the key keeps the stored declaration; an explicit null clears it.
	 *
	 * @param array<string, mixed> $fileData The settings being saved.
	 *
	 * @return array<string, mixed>|null The declaration to store, or null when there is none.
	 *
	 * @throws InvalidArgumentException When the declaration names an ownership nobody can honour.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	private function reviewDefault(array $fileData): ?array {
		if (array_key_exists(ReviewPolicy::SETTINGS_KEY, $fileData) === false) {
			return $this->storedReviewDefault();
		}

		$declaration = $fileData[ReviewPolicy::SETTINGS_KEY];
		if ($declaration === null) {
			return null;
		}

		$errors = (new ReviewAnnotationValidator())->validate(configuration: [ReviewPolicy::ANNOTATION => $declaration]);
		if ($errors !== []) {
			throw new InvalidArgumentException(implode(' ', array_column($errors, 'message')));
		}

		return ReviewPolicy::fromDeclaration(declaration: $declaration)->toArray();
	}//end reviewDefault()

	/**
	 * The review default already stored, kept when a save does not name one.
	 *
	 * @return array<string, mixed>|null The stored policy, or null when none is stored.
	 */
	private function storedReviewDefault(): ?array {
		$stored = ($this->getFileSettingsOnly()[ReviewPolicy::SETTINGS_KEY] ?? null);
		if (is_array($stored) === true) {
			return $stored;
		}

		return null;
	}//end storedReviewDefault()

	/**
	 * The stored OpenAnonymiser password, for the outgoing request only.
	 *
	 * Never return this from a settings endpoint and never log it.
	 *
	 * @return string The password, or an empty string when none is set.
	 *
	 * @spec exclude credential plumbing for the external OpenAnonymiser transport
	 */
	public function getOpenAnonymiserPassword(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::OPENANONYMISER_CREDENTIAL_FIELD, '');
	}//end getOpenAnonymiserPassword()

	/**
	 * Whether an OpenAnonymiser password is stored.
	 *
	 * @return bool True when a non-empty password is stored.
	 */
	private function hasOpenAnonymiserPassword(): bool {
		return $this->getOpenAnonymiserPassword() !== '';
	}//end hasOpenAnonymiserPassword()

	/**
	 * Store or clear the OpenAnonymiser password from a settings write.
	 *
	 * A write that does not carry the key leaves the stored password alone,
	 * so the settings screen can save its other fields without knowing it.
	 * An empty string clears it.
	 *
	 * @param array $fileData The settings being written.
	 *
	 * @return void
	 */
	private function storeOpenAnonymiserPassword(array $fileData): void {
		if (array_key_exists(self::OPENANONYMISER_CREDENTIAL_FIELD, $fileData) === false
			|| is_string($fileData[self::OPENANONYMISER_CREDENTIAL_FIELD]) === false
		) {
			return;
		}

		$this->writeOpenAnonymiserPassword(password: $fileData[self::OPENANONYMISER_CREDENTIAL_FIELD]);
	}//end storeOpenAnonymiserPassword()

	/**
	 * Store the OpenAnonymiser password as a sensitive value, or delete it when blank.
	 *
	 * @param string $password The submitted password; '' clears the stored one.
	 *
	 * @return void
	 */
	private function writeOpenAnonymiserPassword(string $password): void {
		if ($password === '') {
			$this->appConfig->deleteKey(Application::APP_ID, self::OPENANONYMISER_CREDENTIAL_FIELD);
			return;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::OPENANONYMISER_CREDENTIAL_FIELD,
			$password,
			sensitive: true
		);
	}//end writeOpenAnonymiserPassword()
}//end class
