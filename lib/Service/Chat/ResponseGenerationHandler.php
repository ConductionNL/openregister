<?php
/**
 * OpenRegister Chat Response Generation Handler
 *
 * Handler for generating LLM responses using configured providers.
 * Supports OpenAI, Fireworks AI, and Ollama with function calling.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */


namespace OCA\OpenRegister\Service\Chat;

use Exception;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\OpenAIConfig;
use LLPhant\OllamaConfig;

/**
 * ResponseGenerationHandler
 *
 * Handles LLM response generation for chat using various providers.
 * Manages provider configuration, API calls, and function/tool execution.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

class ResponseGenerationHandler
{

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Tool management handler
     *
     * @var ToolManagementHandler
     */
    private ToolManagementHandler $toolHandler;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param SettingsService       $settingsService Settings service for LLM config.
     * @param ToolManagementHandler $toolHandler     Tool management handler.
     * @param LoggerInterface       $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        SettingsService $settingsService,
        ToolManagementHandler $toolHandler,
        LoggerInterface $logger
    ) {
        $this->settingsService = $settingsService;
        $this->toolHandler     = $toolHandler;
        $this->logger          = $logger;

    }//end __construct()


    /**
     * Generate response using configured LLM provider
     *
     * This method handles the complete LLM response generation process including:
     * - Provider configuration (OpenAI, Fireworks AI, Ollama)
     * - Tool/function calling setup
     * - Message history management
     * - Context injection
     * - API communication
     *
     * @param string     $userMessage    User's message text.
     * @param array      $context        RAG context with 'text' and 'sources' keys.
     * @param array      $messageHistory Array of LLPhantMessage objects.
     * @param Agent|null $agent          Agent configuration (optional).
     * @param array      $selectedTools  Tools selected for this request (optional).
     *
     * @return string Generated response text
     *
     * @throws \Exception If LLM provider is not configured or API call fails
     *
     * @psalm-param array{text: string, sources: list<array>} $context
     */
    public function generateResponse(
        string $userMessage,
        array $context,
        array $messageHistory,
        ?Agent $agent,
        array $selectedTools=[]
    ): string {
        $startTime = microtime(true);

        $this->logger->info(
            message: '[ChatService] Generating response',
            context: [
                'messageLength' => strlen($userMessage),
                'contextLength' => strlen($context['text']),
                'historyCount'  => count($messageHistory),
                'selectedTools' => count($selectedTools),
            ]
        );

        // Get enabled tools for agent, filtered by selectedTools.
        $toolsStartTime = microtime(true);
        $tools          = $this->toolHandler->getAgentTools(agent: $agent, selectedTools: $selectedTools);
        $toolsTime      = microtime(true) - $toolsStartTime;
        if (empty($tools) === false) {
            $this->logger->info(
                message: '[ChatService] Agent has tools enabled',
                context: [
                    'toolCount' => count($tools),
                    'tools'     => array_map(fn($tool) => $tool->getName(), $tools),
                ]
            );
        }

        // Get LLM configuration.
        $llmConfig = $this->settingsService->getLLMSettingsOnly();

        // Get chat provider.
        $chatProvider = $llmConfig['chatProvider'] ?? null;

        if (empty($chatProvider) === true) {
            throw new Exception('Chat provider is not configured. Please configure OpenAI, Fireworks AI, or Ollama in settings.');
        }

        $this->logger->info(
            message: '[ChatService] Using chat provider',
            context: [
                'provider'  => $chatProvider,
                'llmConfig' => $llmConfig,
                'hasTools'  => empty($tools) === false,
            ]
        );

        try {
            // Configure LLM client based on provider.
            // Ollama uses its own native config and chat class.
            if ($chatProvider === 'ollama') {
                $ollamaConfig = $llmConfig['ollamaConfig'] ?? [];
                if (empty($ollamaConfig['url']) === true) {
                    throw new Exception('Ollama URL is not configured');
                }

                // Use native Ollama configuration.
                $config      = new OllamaConfig();
                $config->url = rtrim($ollamaConfig['url'], '/').'/api/';
                // Use agent model if set and not empty, otherwise fallback to global config.
                $agentModel = $agent?->getModel();
                if (empty($agentModel) === false) {
                    $config->model = $agentModel;
                } else {
                    $config->model = ($ollamaConfig['chatModel'] ?? 'llama2');
                }

                // Set temperature from agent or default.
                if ($agent?->getTemperature() !== null) {
                    $config->modelOptions['temperature'] = $agent->getTemperature();
                }
            } else {
                // OpenAI and Fireworks use OpenAIConfig.
                $config = new OpenAIConfig();

                if ($chatProvider === 'openai') {
                    $openaiConfig = $llmConfig['openaiConfig'] ?? [];
                    if (empty($openaiConfig['apiKey']) === true) {
                        throw new Exception('OpenAI API key is not configured');
                    }

                    $config->apiKey = $openaiConfig['apiKey'];
                    // Use agent model if set and not empty, otherwise fallback to global config.
                    $agentModel = $agent?->getModel();
                    if (empty($agentModel) === false) {
                        $config->model = $agentModel;
                    } else {
                        $config->model = ($openaiConfig['chatModel'] ?? 'gpt-4o-mini');
                    }

                    if (empty($openaiConfig['organizationId']) === false) {
                        /*
                         * @psalm-suppress UndefinedPropertyAssignment - LLPhant\OpenAIConfig has dynamic properties
                         */

                        $config->organizationId = $openaiConfig['organizationId'];
                    }
                } else if ($chatProvider === 'fireworks') {
                    $fireworksConfig = $llmConfig['fireworksConfig'] ?? [];
                    if (empty($fireworksConfig['apiKey']) === true) {
                        throw new Exception('Fireworks AI API key is not configured');
                    }

                    $config->apiKey = $fireworksConfig['apiKey'];
                    // Use agent model if set and not empty, otherwise fallback to global config.
                    $agentModel = $agent?->getModel();
                    if (empty($agentModel) === false) {
                        $config->model = $agentModel;
                    } else {
                        $config->model = ($fireworksConfig['chatModel'] ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct');
                    }

                    // Fireworks AI uses OpenAI-compatible API.
                    $baseUrl = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
                    if (str_ends_with($baseUrl, '/v1') === false) {
                        $baseUrl .= '/v1';
                    }

                    $config->url = $baseUrl;
                } else {
                    throw new Exception("Unsupported chat provider: {$chatProvider}");
                }//end if

                // Set temperature from agent or default (OpenAI/Fireworks).
                if ($agent?->getTemperature() !== null) {
                    /*
                     * @psalm-suppress UndefinedPropertyAssignment - LLPhant\OpenAIConfig has dynamic properties
                     */

                    $config->temperature = $agent->getTemperature();
                }
            }//end if

            // Build system prompt.
            $systemPrompt = $agent?->getPrompt() ?? "You are a helpful AI assistant that helps users find and understand information in their data.";

            if (empty($context['text']) === false) {
                $systemPrompt .= "\n\nUse the following context to answer the user's question:\n\n";
                $systemPrompt .= "CONTEXT:\n".$context['text']."\n\n";
                $systemPrompt .= "If the context doesn't contain relevant information, say so honestly. ";
                $systemPrompt .= "Always cite which sources you used when answering.";
            }

            // Add system message to history.
            array_unshift($messageHistory, LLPhantMessage::system($systemPrompt));

            // Add current user message.
            $messageHistory[] = LLPhantMessage::user($userMessage);

            // Convert tools to functions if agent has tools enabled.
            $functions = [];
            if (empty($tools) === false) {
                $functions = $this->toolHandler->convertToolsToFunctions($tools);
            }

            // Create chat instance based on provider.
            if ($chatProvider === 'fireworks') {
                // For Fireworks, use direct HTTP to avoid OpenAI library error handling bugs.
                /*
                 * @psalm-suppress UndefinedPropertyFetch - LLPhant\OllamaConfig has dynamic properties
                 */

                $response = $this->callFireworksChatAPIWithHistory(
                    $config->apiKey,
                    $config->model,
                    $config->url,
                    $messageHistory,
                    $functions
                    // Pass functions.
                );
            } else if ($chatProvider === 'ollama') {
                // Use native Ollama chat with LLPhant's built-in tool support.
                $chat = new OllamaChat($config);

                // Add functions if available - Ollama supports tools via LLPhant!
                if (empty($functions) === false) {
                    // Convert array-based function definitions to FunctionInfo objects.
                    $functionInfoObjects = $this->toolHandler->convertFunctionsToFunctionInfo(functions: $functions, tools: $tools);
                    $chat->setTools($functionInfoObjects);
                }

                // Use generateChat() for message arrays.
                $llmStartTime = microtime(true);
                $response     = $chat->generateChat($messageHistory);
                $llmTime      = microtime(true) - $llmStartTime;
            } else {
                // OpenAI chat.
                $chat = new OpenAIChat($config);

                // Add functions if available.
                if (empty($functions) === false) {
                    // Convert array-based function definitions to FunctionInfo objects.
                    $functionInfoObjects = $this->toolHandler->convertFunctionsToFunctionInfo(functions: $functions, tools: $tools);
                    $chat->setTools($functionInfoObjects);
                }

                // Use generateChat() for message arrays, which properly handles tools/functions.
                $llmStartTime = microtime(true);
                $response     = $chat->generateChat($messageHistory);
                $llmTime      = microtime(true) - $llmStartTime;
            }//end if

            $totalTime = microtime(true) - $startTime;

            $this->logger->info(
                message: '[ChatService] Response generated - PERFORMANCE',
                context: [
                    'provider'       => $chatProvider,
                    'model'          => $config->model,
                    'responseLength' => strlen($response),
                    'timings'        => [
                        'total'         => round($totalTime, 2).'s',
                        'toolsLoading'  => round($toolsTime, 3).'s',
                        'llmGeneration' => round($llmTime, 2).'s',
                        'overhead'      => round($totalTime - $llmTime - $toolsTime, 3).'s',
                    ],
                ]
            );

            return $response;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ChatService] Failed to generate response',
                context: [
                    'provider' => $chatProvider ?? 'unknown',
                    'error'    => $e->getMessage(),
                ]
            );
            throw new Exception('Failed to generate response: '.$e->getMessage());
        }//end try

    }//end generateResponse()


    /**
     * Call Fireworks AI chat API directly (simple version)
     *
     * Makes a direct HTTP call to Fireworks AI without using the OpenAI library.
     * This avoids issues with error handling in the library.
     *
     * @param string $apiKey  Fireworks API key.
     * @param string $model   Model identifier.
     * @param string $baseUrl Base API URL.
     * @param string $message User message.
     *
     * @return string Generated response text
     *
     * @throws \Exception If API call fails
     */
    private function callFireworksChatAPI(string $apiKey, string $model, string $baseUrl, string $message): string
    {
        $url = rtrim($baseUrl, '/').'/chat/completions';

        $this->logger->debug(
            message: '[ChatService] Calling Fireworks chat API directly',
            context: [
                'url'   => $url,
                'model' => $model,
            ]
        );

        $payload = [
            'model'    => $model,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => $message,
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
            ]
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new Exception("Fireworks API request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            // Parse error response.
            $errorData    = is_string($response) === true ? json_decode($response, true) : [];
            $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? (is_string($response) === true ? $response : 'Unknown error');

            // Make error messages user-friendly.
            if ($httpCode === 401 || $httpCode === 403) {
                throw new Exception('Authentication failed. Please check your Fireworks API key.');
            } else if ($httpCode === 404) {
                throw new Exception("Model not found: {$model}. Please check the model name.");
            } else if ($httpCode === 429) {
                throw new Exception('Rate limit exceeded. Please try again later.');
            } else {
                throw new Exception("Fireworks API error (HTTP {$httpCode}): {$errorMessage}");
            }
        }

        if (is_string($response) === true) {
            $data = json_decode($response, true);
        } else {
            $data = [];
        }

        if (isset($data['choices'][0]['message']['content']) === false) {
            if (is_string($response) === true) {
                $responseStr = $response;
            } else {
                $responseStr = 'Invalid response';
            }

            throw new Exception("Unexpected Fireworks API response format: ".$responseStr);
        }

        return $data['choices'][0]['message']['content'];

    }//end callFireworksChatAPI()


    /**
     * Call Fireworks AI chat API with full message history
     *
     * Similar to callFireworksChatAPI but supports full conversation history.
     * Converts LLPhant message objects to API format.
     *
     * @param string $apiKey         Fireworks API key.
     * @param string $model          Model identifier.
     * @param string $baseUrl        Base API URL.
     * @param array  $messageHistory Array of LLPhantMessage objects.
     * @param array  $functions      Function definitions for tool calling (optional).
     *
     * @return string Generated response text
     *
     * @throws \Exception If API call fails
     */
    private function callFireworksChatAPIWithHistory(string $apiKey, string $model, string $baseUrl, array $messageHistory, array $functions=[]): string
    {
        $url = rtrim($baseUrl, '/').'/chat/completions';

            // Note: Function calling with Fireworks AI is not yet implemented.
        // Functions will be ignored for Fireworks provider.
        if (empty($functions) === false) {
            $this->logger->warning(
                message: '[ChatService] Function calling not yet supported for Fireworks AI. Tools will be ignored.',
                context: [
                    'functionCount' => count($functions),
                ]
            );
        }

        $this->logger->debug(
            message: '[ChatService] Calling Fireworks chat API with history',
            context: [
                'url'          => $url,
                'model'        => $model,
                'historyCount' => count($messageHistory),
            ]
        );

        // Convert LLPhant messages to API format.
        // LLPhant Message properties are public, so we can access them directly.
        $messages = [];
        foreach ($messageHistory as $msg) {
            // Convert ChatRole enum to string value.
            $roleString = $msg->role->value;
            $content    = $msg->content;

            $messages[] = [
                'role'    => $roleString,
                'content' => $content,
            ];
        }

        // Log final message count.
        $this->logger->debug(
            message: '[ChatService] Prepared messages for API',
            context: [
                'messageCount' => count($messages),
            ]
        );

        $payload = [
            'model'    => $model,
            'messages' => $messages,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
            ]
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        // Longer timeout for conversations.
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new Exception("Fireworks API request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            // Parse error response.
            $errorData    = is_string($response) === true ? json_decode($response, true) : [];
            $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? (is_string($response) === true ? $response : 'Unknown error');

            // Make error messages user-friendly.
            if ($httpCode === 401 || $httpCode === 403) {
                throw new Exception('Authentication failed. Please check your Fireworks API key.');
            } else if ($httpCode === 404) {
                throw new Exception("Model not found: {$model}. Please check the model name.");
            } else if ($httpCode === 429) {
                throw new Exception('Rate limit exceeded. Please try again later.');
            } else {
                throw new Exception("Fireworks API error (HTTP {$httpCode}): {$errorMessage}");
            }
        }

        if (is_string($response) === true) {
            $data = json_decode($response, true);
        } else {
            $data = [];
        }

        if (isset($data['choices'][0]['message']['content']) === false) {
            if (is_string($response) === true) {
                $responseStr = $response;
            } else {
                $responseStr = 'Invalid response';
            }

            throw new Exception("Unexpected Fireworks API response format: ".$responseStr);
        }

        return $data['choices'][0]['message']['content'];

    }//end callFireworksChatAPIWithHistory()


    /**
     * Get URL from LLPhant config object
     *
     * Helper method to safely extract URL from config objects.
     *
     * @param object $llphantConfig LLPhant config object (OpenAIConfig or OllamaConfig).
     *
     * @return string URL or 'default' if not set
     */
    private function getLlphantUrl(object $llphantConfig): string
    {
        if (property_exists($llphantConfig, 'url') === true && empty($llphantConfig->url) === false) {
            return $llphantConfig->url;
        }

        return 'default';

    }//end getLlphantUrl()


}//end class
