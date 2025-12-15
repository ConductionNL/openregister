<?php
/**
 * OpenRegister LLM Settings Handler
 *
 * This file contains the handler class for managing LLM provider configuration.
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
use RuntimeException;
use OCP\IConfig;

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
class LlmSettingsHandler
{

    /**
     * Configuration service
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Application name
     *
     * @var string
     */
    private string $appName;


    /**
     * Constructor for LlmSettingsHandler
     *
     * @param IConfig $config  Configuration service.
     * @param string  $appName Application name.
     *
     * @return void
     */
    public function __construct(
        IConfig $config,
        string $appName='openregister'
    ) {
        $this->config  = $config;
        $this->appName = $appName;

    }//end __construct()


    /**
     * Get LLM settings only.
     *
     * @return array LLM configuration.
     *
     * @throws \RuntimeException If LLM settings retrieval fails.
     */
    public function getLLMSettingsOnly(): array
    {
        try {
            $llmConfig = $this->config->getAppValue($this->appName, 'llm', '');

            if (empty($llmConfig) === true) {
                // Return default configuration.
                return [
                    'enabled'           => false,
                    'embeddingProvider' => null,
                    'chatProvider'      => null,
                    'openaiConfig'      => [
                        'apiKey'         => '',
                        'model'          => null,
                        'chatModel'      => null,
                        'organizationId' => '',
                    ],
                    'ollamaConfig'      => [
                        'url'       => 'http://localhost:11434',
                        'model'     => null,
                        'chatModel' => null,
                    ],
                    'fireworksConfig'   => [
                        'apiKey'         => '',
                        'embeddingModel' => null,
                        'chatModel'      => null,
                        'baseUrl'        => 'https://api.fireworks.ai/inference/v1',
                    ],
                    'vectorConfig'      => [
                        'backend'   => 'php',
                        'solrField' => '_embedding_',
                    ],
                ];
            }//end if

            $decoded = json_decode($llmConfig, true);

            // Ensure enabled field exists (for backward compatibility).
            if (isset($decoded['enabled']) === false) {
                $decoded['enabled'] = false;
            }

            // Ensure vector config exists (for backward compatibility).
            if (isset($decoded['vectorConfig']) === false) {
                $decoded['vectorConfig'] = [
                    'backend'   => 'php',
                    'solrField' => '_embedding_',
                ];
            } else {
                // Ensure all vector config fields exist.
                if (isset($decoded['vectorConfig']['backend']) === false) {
                    $decoded['vectorConfig']['backend'] = 'php';
                }

                if (isset($decoded['vectorConfig']['solrField']) === false) {
                    $decoded['vectorConfig']['solrField'] = '_embedding_';
                }

                // Remove deprecated solrCollection if it exists.
                unset($decoded['vectorConfig']['solrCollection']);
            }

            return $decoded;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve LLM settings: '.$e->getMessage());
        }//end try

    }//end getLLMSettingsOnly()


    /**
     * Update LLM settings only.
     *
     * @param array $llmData LLM configuration data.
     *
     * @return array Updated LLM configuration.
     *
     * @throws \RuntimeException If LLM settings update fails.
     */
    public function updateLLMSettingsOnly(array $llmData): array
    {
        try {
            // Get existing config for PATCH support.
            $existingConfig = $this->getLLMSettingsOnly();

            // Merge with existing config (PATCH behavior).
            $llmConfig = [
                'enabled'           => $llmData['enabled'] ?? $existingConfig['enabled'] ?? false,
                'embeddingProvider' => $llmData['embeddingProvider'] ?? $existingConfig['embeddingProvider'] ?? null,
                'chatProvider'      => $llmData['chatProvider'] ?? $existingConfig['chatProvider'] ?? null,
                'openaiConfig'      => [
                    'apiKey'         => $llmData['openaiConfig']['apiKey'] ?? $existingConfig['openaiConfig']['apiKey'] ?? '',
                    'model'          => $llmData['openaiConfig']['model'] ?? $existingConfig['openaiConfig']['model'] ?? null,
                    'chatModel'      => $llmData['openaiConfig']['chatModel'] ?? $existingConfig['openaiConfig']['chatModel'] ?? null,
                    'organizationId' => $llmData['openaiConfig']['organizationId'] ?? $existingConfig['openaiConfig']['organizationId'] ?? '',
                ],
                'ollamaConfig'      => [
                    'url'       => $llmData['ollamaConfig']['url'] ?? $existingConfig['ollamaConfig']['url'] ?? 'http://localhost:11434',
                    'model'     => $llmData['ollamaConfig']['model'] ?? $existingConfig['ollamaConfig']['model'] ?? null,
                    'chatModel' => $llmData['ollamaConfig']['chatModel'] ?? $existingConfig['ollamaConfig']['chatModel'] ?? null,
                ],
                'fireworksConfig'   => [
                    'apiKey'         => $llmData['fireworksConfig']['apiKey'] ?? $existingConfig['fireworksConfig']['apiKey'] ?? '',
                    'embeddingModel' => $llmData['fireworksConfig']['embeddingModel'] ?? $existingConfig['fireworksConfig']['embeddingModel'] ?? null,
                    'chatModel'      => $llmData['fireworksConfig']['chatModel'] ?? $existingConfig['fireworksConfig']['chatModel'] ?? null,
                    'baseUrl'        => $llmData['fireworksConfig']['baseUrl'] ?? $existingConfig['fireworksConfig']['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1',
                ],
                'vectorConfig'      => [
                    'backend'   => $llmData['vectorConfig']['backend'] ?? $existingConfig['vectorConfig']['backend'] ?? 'php',
                    'solrField' => $llmData['vectorConfig']['solrField'] ?? $existingConfig['vectorConfig']['solrField'] ?? '_embedding_',
                ],
            ];

            $this->config->setAppValue($this->appName, 'llm', json_encode($llmConfig));
            return $llmConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update LLM settings: '.$e->getMessage());
        }//end try

    }//end updateLLMSettingsOnly()


}//end class
