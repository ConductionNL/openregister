<?php

/**
 * OpenRegister LLM Settings Handler
 *
 * This file contains the handler class for managing LLM provider configuration.
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
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Handler for LLM (Language Model) settings operations.
 *
 * This handler is responsible for managing LLM provider configuration including
 * OpenAI, Ollama, and Fireworks settings, along with vector embedding configuration.
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
class LlmSettingsHandler {

	/**
	 * The LLM settings returned when none are stored, and the default of every key on update.
	 *
	 * @var array<string, mixed>
	 */
	private const LLM_DEFAULTS = [
		'enabled' => false,
		'embeddingProvider' => null,
		'chatProvider' => null,
		'openaiConfig' => [
			'apiKey' => '',
			'model' => null,
			'chatModel' => null,
			'organizationId' => '',
		],
		'ollamaConfig' => [
			'url' => 'http://localhost:11434',
			'model' => null,
			'chatModel' => null,
		],
		'fireworksConfig' => [
			'apiKey' => '',
			'embeddingModel' => null,
			'chatModel' => null,
			'baseUrl' => 'https://api.fireworks.ai/inference/v1',
		],
		'vectorConfig' => [
			'backend' => 'php',
		],
	];

	/**
	 * Configuration service
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;


	/**
	 * Records every save on the audit trail (openregister#4100).
	 *
	 * @var OwnSettingsChangeRecorder|null
	 */
	private ?OwnSettingsChangeRecorder $changeRecorder;

	/**
	 * Constructor for LlmSettingsHandler
	 *
	 * @param IAppConfig $appConfig Configuration service.
	 * @param OwnSettingsChangeRecorder|null $changeRecorder Records every save on the audit trail.
	 *
	 * @return void
	 */
	public function __construct(
		IAppConfig $appConfig,
		?OwnSettingsChangeRecorder $changeRecorder = null,
	) {
		$this->appConfig = $appConfig;
		$this->changeRecorder = $changeRecorder;
	}//end __construct()

	/**
	 * Get LLM settings only.
	 *
	 * @return array LLM configuration.
	 *
	 * @throws \RuntimeException If LLM settings retrieval fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getLLMSettingsOnly(): array {
		try {
			$llmConfig = $this->appConfig->getValueString(AppIdentity::APP_ID, 'llm', '');
			if (empty($llmConfig) === true) {
				return self::LLM_DEFAULTS;
			}

			return $this->withCurrentShape(decoded: json_decode($llmConfig, true));
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve LLM settings: ' . $e->getMessage());
		}//end try
	}//end getLLMSettingsOnly()

	/**
	 * Bring stored LLM settings to the current shape: an enabled flag and a
	 * vector backend for settings stored before they existed, without the
	 * retired Solr vector keys.
	 *
	 * @param mixed $decoded The decoded stored settings.
	 *
	 * @return mixed
	 */
	private function withCurrentShape($decoded) {
		if (isset($decoded['enabled']) === false) {
			$decoded['enabled'] = false;
		}

		if (isset($decoded['vectorConfig']) === false) {
			$decoded['vectorConfig'] = [];
		}

		if (isset($decoded['vectorConfig']['backend']) === false) {
			$decoded['vectorConfig']['backend'] = 'php';
		}

		unset($decoded['vectorConfig']['solrField'], $decoded['vectorConfig']['solrCollection']);

		return $decoded;
	}//end withCurrentShape()

	/**
	 * Update LLM settings only.
	 *
	 * @param array $llmData LLM configuration data.
	 *
	 * @return array Updated LLM settings with provider configs and vector settings.
	 *
	 * @throws \RuntimeException If LLM settings update fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateLLMSettingsOnly(array $llmData): array {
		try {
			// PATCH: a submitted value wins, then the stored one, then the default.
			$llmConfig = $this->patched(defaults: self::LLM_DEFAULTS, layers: [$llmData, $this->getLLMSettingsOnly()]);

			$before = $this->changeRecorder?->snapshot(keys: ['llm']);
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'llm', json_encode($llmConfig));
			if ($before !== null) {
				$this->changeRecorder?->record(before: $before, keys: ['llm']);
			}

			return $llmConfig;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update LLM settings: ' . $e->getMessage());
		}//end try
	}//end updateLLMSettingsOnly()

	/**
	 * Every default key with the value of the first layer that sets it, or the
	 * default; a key whose default is an array is patched the same way, one level down.
	 *
	 * @param array<string, mixed> $defaults The keys and their defaults.
	 * @param array{0: mixed, 1: mixed} $layers The submitted and the stored settings.
	 *
	 * @return array<string, mixed>
	 */
	private function patched(array $defaults, array $layers): array {
		[$submitted, $stored] = $layers;
		$config = [];
		foreach ($defaults as $key => $default) {
			if (is_array($default) === true) {
				$config[$key] = $this->patched(defaults: $default, layers: [$submitted[$key] ?? [], $stored[$key] ?? []]);
				continue;
			}

			$config[$key] = $submitted[$key] ?? $stored[$key] ?? $default;
		}

		return $config;
	}//end patched()
}//end class
