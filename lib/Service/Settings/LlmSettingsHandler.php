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
use OCP\IAppConfig;

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
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Application name
     *
     * @var string
     */
    private string $appName;

    /**
     * Constructor for LlmSettingsHandler
     *
     * @param IAppConfig $appConfig Configuration service.
     * @param string     $appName   Application name.
     *
     * @return void
     */
    public function __construct(
        IAppConfig $appConfig,
        string $appName='openregister'
    ) {
        $this->appConfig = $appConfig;
        $this->appName   = $appName;
    }//end __construct()

    /**
     * Get LLM settings only.
     *
     * @return array LLM configuration.
     *
     * @throws \RuntimeException If LLM settings retrieval fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *     Backward compatibility requires multiple field existence checks
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *     Default configuration structure requires comprehensive initialization
     * @SuppressWarnings(PHPMD.ElseExpression)
     *     Nested else branches handle optional vector config backward compatibility
     */
    public function getLLMSettingsOnly(): array
    {
        try {
            $llmConfig = $this->appConfig->getValueString($this->appName, 'llm', '');

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
     * @return array Updated LLM settings with provider configs and vector settings.
     *
     * @throws \RuntimeException If LLM settings update fails.
     *
     * @SuppressWarnings(PHPMD.NPathComplexity) PATCH behavior requires merging multiple nested configuration structures
     */
    public function updateLLMSettingsOnly(array $llmData): array
    {
        try {
            // Get existing config for PATCH support.
            $existingConfig = $this->getLLMSettingsOnly();

            // Create shorter refs to sub-configs for readability.
            $newOai = $llmData['openaiConfig'] ?? [];
            $exOai  = $existingConfig['openaiConfig'] ?? [];
            $newOll = $llmData['ollamaConfig'] ?? [];
            $exOll  = $existingConfig['ollamaConfig'] ?? [];
            $newFw  = $llmData['fireworksConfig'] ?? [];
            $exFw   = $existingConfig['fireworksConfig'] ?? [];
            $newVec = $llmData['vectorConfig'] ?? [];
            $exVec  = $existingConfig['vectorConfig'] ?? [];

            // Merge with existing config (PATCH behavior).
            $llmConfig = [
                'enabled'           => $llmData['enabled'] ?? $existingConfig['enabled'] ?? false,
                'embeddingProvider' => $llmData['embeddingProvider'] ?? $existingConfig['embeddingProvider'] ?? null,
                'chatProvider'      => $llmData['chatProvider'] ?? $existingConfig['chatProvider'] ?? null,
                'openaiConfig'      => [
                    'apiKey'         => $newOai['apiKey'] ?? $exOai['apiKey'] ?? '',
                    'model'          => $newOai['model'] ?? $exOai['model'] ?? null,
                    'chatModel'      => $newOai['chatModel'] ?? $exOai['chatModel'] ?? null,
                    'organizationId' => $newOai['organizationId'] ?? $exOai['organizationId'] ?? '',
                ],
                'ollamaConfig'      => [
                    'url'       => $newOll['url'] ?? $exOll['url'] ?? 'http://localhost:11434',
                    'model'     => $newOll['model'] ?? $exOll['model'] ?? null,
                    'chatModel' => $newOll['chatModel'] ?? $exOll['chatModel'] ?? null,
                ],
                'fireworksConfig'   => [
                    'apiKey'         => $newFw['apiKey'] ?? $exFw['apiKey'] ?? '',
                    'embeddingModel' => $newFw['embeddingModel'] ?? $exFw['embeddingModel'] ?? null,
                    'chatModel'      => $newFw['chatModel'] ?? $exFw['chatModel'] ?? null,
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- URL cannot be split
                    'baseUrl'        => $newFw['baseUrl'] ?? $exFw['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1',
                ],
                'vectorConfig'      => [
                    'backend'   => $newVec['backend'] ?? $exVec['backend'] ?? 'php',
                    'solrField' => $newVec['solrField'] ?? $exVec['solrField'] ?? '_embedding_',
                ],
            ];

            $this->appConfig->setValueString($this->appName, 'llm', json_encode($llmConfig));
            return $llmConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update LLM settings: '.$e->getMessage());
        }//end try
    }//end updateLLMSettingsOnly()
}//end class
