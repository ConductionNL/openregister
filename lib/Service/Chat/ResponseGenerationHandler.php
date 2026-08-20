<?php

/**
 * OpenRegister Chat Response Generation Handler
 *
 * Handler for generating LLM responses using configured providers.
 * Supports OpenAI, Fireworks AI, and Ollama with function calling.
 *
 * Streaming-mode outcome (orchestrator §1 + streaming follow-up §2):
 * dual-mode. When the caller passes a `StreamYieldChannel` AND the
 * configured provider's chat instance exposes `generateStreamOfText`,
 * we call `generateChatStream($messages)` and iterate its PSR-7
 * stream, forwarding each chunk to `$channel->emitToken()`. When no
 * channel is supplied (the existing `POST /api/chat/send` path,
 * background workers) we keep the blocking `generateChat()` call
 * exactly as before — that branch is load-bearing for the
 * non-streaming endpoint and must not regress.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Service\Chat;

use Exception;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Exception\MissingFeatureException;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tool\StreamingToolInstanceWrapper;
use Psr\Log\LoggerInterface;
use ReflectionClass;

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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   This class is a multi-provider LLM router that
 *   must reference OpenAI, Ollama, and Fireworks config/chat classes plus the streaming
 *   infrastructure (StreamYieldChannel, StreamingToolInstanceWrapper, MissingFeatureException).
 *   Splitting into one class per provider would be the clean long-term solution; for now all
 *   13 imported types are genuinely load-bearing and cannot be consolidated without an
 *   architectural refactor tracked as a separate ADR task.
 */
class ResponseGenerationHandler {

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
	 * Token/latency usage from the last generateResponse() call, for per-run cost recording
	 * (run-analytics). Populated from the LLPhant chat instance; empty when the provider
	 * does not expose usage. Keys: promptTokens, completionTokens, totalDurationMs, llmSeconds.
	 *
	 * @var array<string, int|float>
	 */
	public array $lastUsage = [];

	/**
	 * Constructor
	 *
	 * @param SettingsService $settingsService Settings service for LLM config.
	 * @param ToolManagementHandler $toolHandler Tool management handler.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		SettingsService $settingsService,
		ToolManagementHandler $toolHandler,
		LoggerInterface $logger,
	) {
		$this->settingsService = $settingsService;
		$this->toolHandler = $toolHandler;
		$this->logger = $logger;
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
	 * When `$channel` is non-null AND the active provider's chat instance
	 * exposes `generateStreamOfText` we invoke the streaming surface and
	 * forward each token chunk through `$channel->emitToken()`. On
	 * `LLPhant\Exception\MissingFeatureException` we fall through to the
	 * blocking call so providers that advertise streaming but fail at
	 * runtime degrade gracefully (contract's non-streaming-provider clause).
	 *
	 * @param string $userMessage User's message text.
	 * @param array $context RAG context with 'text' and 'sources' keys.
	 * @param array $messageHistory Array of LLPhantMessage objects.
	 * @param Agent|null $agent Agent configuration (optional).
	 * @param array $selectedTools Tools selected for this request (optional).
	 * @param StreamYieldChannel|null $channel Streaming channel; when supplied the handler
	 *                                         attempts the LLPhant streaming surface and
	 *                                         forwards token / tool-call / tool-result
	 *                                         events to the channel. When null the handler
	 *                                         runs in legacy blocking mode (load-bearing
	 *                                         for `POST /api/chat/send`).
	 * @param array $cnAiContext Optional Conduction AI context overrides
	 *                           (provider/model hints, defaults to empty array).
	 *
	 * @return string Generated response text
	 *
	 * @throws \Exception If LLM provider is not configured or API call fails
	 *
	 * @psalm-param  array{text: string, sources: list<array>} $context
	 * @psalm-return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          LLPhantMessage factory methods are standard LLPhant pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Response generation requires many conditional API calls
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Response generation requires many conditional API calls
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) LLM provider configuration cannot be easily split
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function generateResponse(
		string $userMessage,
		array $context,
		array $messageHistory,
		?Agent $agent,
		array $selectedTools = [],
		?StreamYieldChannel $channel = null,
		array $cnAiContext = [],
	): string {
		$startTime = microtime(true);

		$this->logger->info(
			message: '[ResponseGenerationHandler] Generating response',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'messageLength' => strlen($userMessage),
				'contextLength' => strlen($context['text']),
				'historyCount' => count($messageHistory),
				'selectedTools' => count($selectedTools),
			]
		);

		// Get enabled tools for agent, filtered by selectedTools.
		$toolsStartTime = microtime(true);
		$tools = $this->toolHandler->getAgentTools(agent: $agent, selectedTools: $selectedTools);
		$toolsTime = microtime(true) - $toolsStartTime;
		if (empty($tools) === false) {
			$this->logger->info(
				message: '[ResponseGenerationHandler] Agent has tools enabled',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'toolCount' => count($tools),
					'tools' => array_map(fn ($tool) => $tool->getName(), $tools),
				]
			);
		}

		// Get LLM configuration.
		$llmConfig = $this->settingsService->getLLMSettingsOnly();

		// Get chat provider.
		$chatProvider = $llmConfig['chatProvider'] ?? null;

		if (empty($chatProvider) === true) {
			throw new Exception(
				'Chat provider is not configured. Please configure OpenAI, Fireworks AI, or Ollama in settings.',
				503
			);
		}

		$this->logger->info(
			message: '[ResponseGenerationHandler] Using chat provider',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $chatProvider,
				'llmConfig' => $llmConfig,
				'hasTools' => empty($tools) === false,
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
				$config = new OllamaConfig();
				$config->url = rtrim($ollamaConfig['url'], '/') . '/api/';
				// Use agent model if set and not empty, otherwise fallback to global config.
				$agentModel = $agent?->getModel();
				$config->model = ($ollamaConfig['chatModel'] ?? 'llama2');
				if (empty($agentModel) === false) {
					$config->model = $agentModel;
				}

				// Set temperature from agent or default.
				if ($agent?->getTemperature() !== null) {
					$config->modelOptions['temperature'] = $agent->getTemperature();
				}
			} elseif ($chatProvider === 'openai') {
				// OpenAI uses OpenAIConfig.
				$config = new OpenAIConfig();

				$openaiConfig = $llmConfig['openaiConfig'] ?? [];
				if (empty($openaiConfig['apiKey']) === true) {
					throw new Exception('OpenAI API key is not configured', 503);
				}

				$config->apiKey = $openaiConfig['apiKey'];
				// Use agent model if set and not empty, otherwise fallback to global config.
				$agentModel = $agent?->getModel();
				$config->model = ($openaiConfig['chatModel'] ?? 'gpt-4o-mini');
				if (empty($agentModel) === false) {
					$config->model = $agentModel;
				}

				if (empty($openaiConfig['organizationId']) === false) {
					/*
					 * @psalm-suppress UndefinedPropertyAssignment LLPhant dynamic properties
					 */

					$config->organizationId = $openaiConfig['organizationId'];
				}

				// Set temperature from agent or default (OpenAI).
				if ($agent?->getTemperature() !== null) {
					/*
					 * @psalm-suppress UndefinedPropertyAssignment LLPhant dynamic properties
					 */

					$config->temperature = $agent->getTemperature();
				}
			} elseif ($chatProvider === 'fireworks') {
				// Fireworks uses OpenAIConfig.
				$config = new OpenAIConfig();

				$fireworksConfig = $llmConfig['fireworksConfig'] ?? [];
				if (empty($fireworksConfig['apiKey']) === true) {
					throw new Exception('Fireworks AI API key is not configured', 503);
				}

				$config->apiKey = $fireworksConfig['apiKey'];
				// Use agent model if set and not empty, otherwise fallback to global config.
				$agentModel = $agent?->getModel();
				$config->model = ($fireworksConfig['chatModel'] ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct');
				if (empty($agentModel) === false) {
					$config->model = $agentModel;
				}

				// Fireworks AI uses OpenAI-compatible API.
				$baseUrl = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
				if (str_ends_with($baseUrl, '/v1') === false) {
					$baseUrl .= '/v1';
				}

				$config->url = $baseUrl;

				// Set temperature from agent or default (Fireworks).
				if ($agent?->getTemperature() !== null) {
					/*
					 * @psalm-suppress UndefinedPropertyAssignment LLPhant dynamic properties
					 */

					$config->temperature = $agent->getTemperature();
				}
			}//end if

			if ($chatProvider !== 'ollama' && $chatProvider !== 'openai' && $chatProvider !== 'fireworks') {
				throw new Exception("Unsupported chat provider: {$chatProvider}");
			}

			// Build system prompt.
			$defaultPrompt = 'You are a helpful AI assistant that helps users find and understand their data.';
			$systemPrompt = $agent?->getPrompt() ?? $defaultPrompt;

			// Inject the CnAiContext snapshot the widget sends with each
			// message. Without this the LLM has no idea which app the user
			// is in — so on /apps/openbuild/ it would call decidesk tools
			// (or default to OpenRegister-platform language) instead of
			// routing to openbuild.*. The snapshot is small and free-form
			// (typically {app, slug, view, objectId}); we render it as a
			// bullet list so the model can quote individual fields.
			if (empty($cnAiContext) === false) {
				$systemPrompt .= "\n\nCURRENT APP CONTEXT (this is where the user is RIGHT NOW — prefer tools that match this app):\n";
				foreach ($cnAiContext as $key => $value) {
					if (is_scalar($value) === true) {
						$systemPrompt .= "- {$key}: " . (string)$value . "\n";
						continue;
					}

					$systemPrompt .= "- {$key}: " . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
				}
			}

			if (empty($context['text']) === false) {
				$systemPrompt .= "\n\nUse the following context to answer the user's question:\n\n";
				$systemPrompt .= "CONTEXT:\n" . $context['text'] . "\n\n";
				$systemPrompt .= "If the context doesn't contain relevant information, say so honestly. ";
				$systemPrompt .= 'Always cite which sources you used when answering.';
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

			// Initialize $response (and $llmTime, $chat) BEFORE entering any
			// provider branch. The Ollama branch skips the OpenAIChat
			// initialisation block; without this default-empty seed the
			// logger access on `$response` below would tank with an
			// undefined-variable error if every provider branch chose
			// not to assign — an easy regression vector when a new
			// provider is added. The Fireworks/Ollama branches below
			// overwrite this unconditionally for their own provider.
			// $chat = null keeps the `instanceof OllamaChat` usage-capture
			// check below well-defined on every provider path.
			$chat = null;
			$response = '';
			$llmTime = 0.0;
			$llmStartTime = microtime(true);

			// Skip the OpenAIChat instantiation for Ollama — Ollama uses OllamaConfig + OllamaChat
			// (instantiated in the dedicated branch below). OpenAIChat::__construct() type-errors when
			// given OllamaConfig.
			if ($chatProvider !== 'ollama') {
				// Create chat instance based on provider (OpenAI / Fireworks both use OpenAIConfig).
				$chat = new OpenAIChat($config);

				// Add functions if available.
				if (empty($functions) === false) {
					// Convert array-based function definitions to FunctionInfo objects.
					$functionInfoObjects = $this->toolHandler->convertFunctionsToFunctionInfo(
						functions: $functions,
						tools: $tools
					);
					$functionInfoObjects = $this->wrapToolsForStreaming(
						functionInfoObjects: $functionInfoObjects,
						channel: $channel
					);
					$chat->setTools($functionInfoObjects);
				}

				$response = $this->invokeChat(
					chat: $chat,
					messageHistory: $messageHistory,
					channel: $channel,
					provider: $chatProvider
				);
				$llmTime = microtime(true) - $llmStartTime;
			}//end if

			if ($chatProvider === 'fireworks') {
				/*
				 * For Fireworks, use direct HTTP to avoid OpenAI library error handling bugs.
				 *
				 * @psalm-suppress UndefinedPropertyFetch LLPhant config has dynamic properties
				 */

				$response = $this->callFireworksChatAPIWithHistory(
					apiKey: $config->apiKey,
					model: $config->model,
					baseUrl: $config->url,
					messageHistory: $messageHistory,
					functions: $functions
				);
				$llmTime = microtime(true) - $llmStartTime;
			} elseif ($chatProvider === 'ollama') {
				// Use native Ollama chat with LLPhant's built-in tool support.
				$chat = new OllamaChat($config);

				// Add functions if available - Ollama supports tools via LLPhant!
				if (empty($functions) === false) {
					// Convert array-based function definitions to FunctionInfo objects.
					$functionInfoObjects = $this->toolHandler->convertFunctionsToFunctionInfo(
						functions: $functions,
						tools: $tools
					);
					$functionInfoObjects = $this->wrapToolsForStreaming(
						functionInfoObjects: $functionInfoObjects,
						channel: $channel
					);
					$chat->setTools($functionInfoObjects);
				}

				$response = $this->invokeChat(
					chat: $chat,
					messageHistory: $messageHistory,
					channel: $channel,
					provider: $chatProvider
				);
				$llmTime = microtime(true) - $llmStartTime;
			}//end if

			$totalTime = microtime(true) - $startTime;

			$this->logger->info(
				message: '[ResponseGenerationHandler] Response generated - PERFORMANCE',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'provider' => $chatProvider,
					'model' => $config->model,
					'responseLength' => strlen($response),
					'timings' => [
						'total' => round($totalTime, 2) . 's',
						'toolsLoading' => round($toolsTime, 3) . 's',
						'llmGeneration' => round($llmTime, 2) . 's',
						'overhead' => round($totalTime - $llmTime - $toolsTime, 3) . 's',
					],
				]
			);

			// Expose the LLM token/latency usage for per-run cost recording (run-analytics).
			// Only OllamaChat accumulates usage today; other providers leave it empty.
			$this->lastUsage = [];
			if ($chat instanceof OllamaChat) {
				$this->lastUsage = $chat->lastUsage;
			}

			$this->lastUsage['llmSeconds'] = round($llmTime, 2);

			return $response;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ResponseGenerationHandler] Failed to generate response',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'provider' => $chatProvider ?? 'unknown',
					'error' => $e->getMessage(),
				]
			);
			throw new Exception('Failed to generate response: ' . $e->getMessage(), $e->getCode(), $e);
		}//end try
	}//end generateResponse()

	/**
	 * Invoke the active LLPhant chat instance, preferring streaming when a
	 * channel was supplied AND the provider exposes `generateStreamOfText`.
	 *
	 * When the streaming attempt fails with `MissingFeatureException` we
	 * fall through to the blocking `generateChat()` call so the
	 * non-streaming-provider degradation clause of the SSE contract
	 * still holds. The runtime `method_exists` + try/catch combination
	 * is the authority — the provider table in design D4 is informational.
	 *
	 * @param OpenAIChat|OllamaChat $chat Configured LLPhant chat instance.
	 * @param array $messageHistory Array of LLPhantMessage objects.
	 * @param StreamYieldChannel|null $channel Streaming channel, or null for blocking mode.
	 * @param string $provider Provider identifier for log context.
	 *
	 * @return string Full assistant text (concatenation of streamed chunks
	 *                when streaming was used).
	 */

	/**
	 * Wrap each FunctionInfo's tool instance with a
	 * StreamingToolInstanceWrapper when streaming is active so
	 * LLPhant's `$instance->{$func}(...$args)` dispatch goes through
	 * the wrapper's `__call` and fans `tool_call` + `tool_result`
	 * SSE frames out to the channel. When `$channel === null` the
	 * function info list passes through verbatim — load-bearing for
	 * the blocking `POST /api/chat/send` path.
	 *
	 * @param array $functionInfoObjects LLPhant FunctionInfo[] from
	 *                                   ToolManagementHandler::convertFunctionsToFunctionInfo.
	 * @param StreamYieldChannel|null $channel Streaming channel, or null for blocking mode.
	 *
	 * @return array FunctionInfo[] with `$instance` rewritten when wrapping is active.
	 *
	 * @psalm-param  list<\LLPhant\Chat\FunctionInfo\FunctionInfo> $functionInfoObjects
	 * @psalm-return list<\LLPhant\Chat\FunctionInfo\FunctionInfo>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function wrapToolsForStreaming(array $functionInfoObjects, ?StreamYieldChannel $channel): array {
		if ($channel === null) {
			return $functionInfoObjects;
		}

		foreach ($functionInfoObjects as $fi) {
			if (is_object($fi->instance) === true) {
				$fi->instance = new StreamingToolInstanceWrapper(
					wrapped: $fi->instance,
					channel: $channel
				);
			}
		}

		return $functionInfoObjects;
	}//end wrapToolsForStreaming()

	/**
	 * Invoke the configured chat client, preferring streaming where possible.
	 *
	 * @param OpenAIChat|OllamaChat $chat Configured chat client.
	 * @param array $messageHistory LLPhant message history.
	 * @param StreamYieldChannel|null $channel Optional streaming channel.
	 * @param string $provider Provider slug (for logging).
	 *
	 * @return string The assistant's textual response.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function invokeChat(
		OpenAIChat|OllamaChat $chat,
		array $messageHistory,
		?StreamYieldChannel $channel,
		string $provider,
	): string {
		// Ollama-with-tools degrades to blocking. OllamaChat::generateChatStream
		// does NOT process tool_calls — it just streams the raw assistant
		// delta. Only the blocking `generateChat` path handles the
		// tool-call branch and calls our wrapped FunctionInfo instances.
		// OpenAI's createStreamedResponse handles tool_calls during the
		// stream, so it stays on the streaming path.
		$ollamaWithTools = ($chat instanceof OllamaChat) && $this->chatHasTools(chat: $chat);

		if ($channel !== null
			&& $ollamaWithTools === false
			&& method_exists($chat, 'generateStreamOfText') === true
		) {
			try {
				return $this->streamChat(chat: $chat, messageHistory: $messageHistory, channel: $channel);
			} catch (MissingFeatureException $e) {
				// Provider advertises streaming but cannot deliver — log + degrade.
				$this->logger->info(
					message: '[ResponseGenerationHandler] Streaming unavailable, falling back to blocking call',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'provider' => $provider,
						'error' => $e->getMessage(),
					]
				);
			}
		}

		// Non-streaming fallback — load-bearing for POST /api/chat/send,
		// for providers without streaming support (Fireworks today), and
		// for Ollama-with-tools where our StreamingToolInstanceWrapper
		// still fires from the blocking callFunction path so tool_call /
		// tool_result frames reach the SSE consumer even without token
		// streaming.
		return $chat->generateChat($messageHistory);
	}//end invokeChat()

	/**
	 * Reflect into LLPhant's chat instance to check whether any tools are
	 * registered. The `tools` property is protected on both OpenAIChat
	 * and OllamaChat; we read it via reflection to avoid forking LLPhant
	 * just to add a public getter.
	 *
	 * @param OpenAIChat|OllamaChat $chat Configured chat instance
	 *
	 * @return bool True when the instance has at least one FunctionInfo registered
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function chatHasTools(OpenAIChat|OllamaChat $chat): bool {
		try {
			$refl = new ReflectionClass($chat);
			if ($refl->hasProperty(name: 'tools') === false) {
				return false;
			}

			$prop = $refl->getProperty(name: 'tools');
			$prop->setAccessible(accessible: true);
			$tools = $prop->getValue($chat);
			return is_array($tools) && $tools !== [];
		} catch (\Throwable) {
			return false;
		}
	}//end chatHasTools()

	/**
	 * Drive the LLPhant streaming surface, forwarding each chunk to the
	 * channel's `emitToken` callback and assembling the full text for the
	 * final SSE frame.
	 *
	 * Token / tool-call separation: LLPhant's streaming generator handles
	 * tool invocation internally (see `OpenAIChat::createStreamedResponse`)
	 * — when the LLM emits `finish_reason=tool_calls` LLPhant calls the
	 * registered FunctionInfo callback itself and then resumes the stream
	 * via a follow-up `generateChat` call. The assembled tool output ends
	 * up inside the streamed text. We therefore emit each chunk as a
	 * `token` frame and the eventual `final` frame still carries the
	 * complete assistant turn. Exposing `tool_call` / `tool_result` as
	 * distinct SSE frames during streaming would require patching LLPhant
	 * to surface per-invocation hooks; tracked separately.
	 *
	 * @param OpenAIChat|OllamaChat $chat Configured chat instance.
	 * @param array $messageHistory Array of LLPhantMessage objects.
	 * @param StreamYieldChannel $channel Channel to forward chunks to.
	 *
	 * @return string Assembled assistant text.
	 *
	 * @throws MissingFeatureException When the provider's streaming surface throws.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function streamChat(
		OpenAIChat|OllamaChat $chat,
		array $messageHistory,
		StreamYieldChannel $channel,
	): string {
		$stream = $chat->generateChatStream($messageHistory);
		$assembled = '';

		// PSR-7 StreamInterface: read until EOF; LLPhant emits one chunk
		// per delta when `delta.content` is non-empty.
		while ($stream->eof() === false) {
			$chunk = $stream->read(1024);
			if ($chunk === '') {
				continue;
			}

			$assembled .= $chunk;
			$channel->emitToken(delta: $chunk);
		}

		return $assembled;
	}//end streamChat()

	/**
	 * Call Fireworks AI chat API with full message history
	 *
	 * Similar to callFireworksChatAPI but supports full conversation history.
	 * Converts LLPhant message objects to API format.
	 *
	 * @param string $apiKey Fireworks API key.
	 * @param string $model Model identifier.
	 * @param string $baseUrl Base API URL.
	 * @param array $messageHistory Array of LLPhantMessage objects.
	 * @param array $functions Function definitions for tool calling (optional).
	 *
	 * @return string Generated response text
	 *
	 * @throws \Exception If API call fails
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  API call requires handling many response scenarios
	 * @SuppressWarnings(PHPMD.NPathComplexity)       API call requires handling many response scenarios
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) API error handling requires verbose code
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function callFireworksChatAPIWithHistory(
		string $apiKey,
		string $model,
		string $baseUrl,
		array $messageHistory,
		array $functions = [],
	): string {
		$url = rtrim($baseUrl, '/') . '/chat/completions';

		// Note: Function calling with Fireworks AI is not yet implemented.
		// Functions will be ignored for Fireworks provider.
		if (empty($functions) === false) {
			$msg = '[ResponseGenerationHandler] Function calling not yet supported for Fireworks AI. Tools will be ignored.';
			$this->logger->warning(
				message: $msg,
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'functionCount' => count($functions),
				]
			);
		}

		$this->logger->debug(
			message: '[ResponseGenerationHandler] Calling Fireworks chat API with history',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'url' => $url,
				'model' => $model,
				'historyCount' => count($messageHistory),
			]
		);

		// Convert LLPhant messages to API format.
		// LLPhant Message properties are public, so we can access them directly.
		$messages = [];
		foreach ($messageHistory as $msg) {
			// Convert ChatRole enum to string value.
			$roleString = $msg->role->value;
			$content = $msg->content;

			$messages[] = [
				'role' => $roleString,
				'content' => $content,
			];
		}

		// Log final message count.
		$this->logger->debug(
			message: '[ResponseGenerationHandler] Prepared messages for API',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'messageCount' => count($messages),
			]
		);

		$payload = [
			'model' => $model,
			'messages' => $messages,
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			[
				'Authorization: Bearer ' . $apiKey,
				'Content-Type: application/json',
			]
		);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		// Longer timeout for conversations.
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		// No curl_close(): deprecated since PHP 8.0 and a no-op — the
		// CurlHandle object is freed when it goes out of scope.
		if ($curlError !== '') {
			throw new Exception("Fireworks API request failed: {$curlError}");
		}

		if ($httpCode !== 200) {
			// Parse error response.
			$errorData = [];
			if (is_string($response) === true) {
				$errorData = json_decode($response, true);
			}

			$fallbackError = 'Unknown error';
			if (is_string($response) === true) {
				$fallbackError = $response;
			}

			$errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? $fallbackError;

			// Make error messages user-friendly.
			if ($httpCode === 401 || $httpCode === 403) {
				throw new Exception('Authentication failed. Please check your Fireworks API key.');
			}

			if ($httpCode === 404) {
				throw new Exception("Model not found: {$model}. Please check the model name.");
			}

			if ($httpCode === 429) {
				throw new Exception('Rate limit exceeded. Please try again later.');
			}

			throw new Exception("Fireworks API error (HTTP {$httpCode}): {$errorMessage}");
		}//end if

		$data = [];
		if (is_string($response) === true) {
			$data = json_decode($response, true);
		}

		if (isset($data['choices'][0]['message']['content']) === false) {
			$responseStr = 'Invalid response';
			if (is_string($response) === true) {
				$responseStr = $response;
			}

			throw new Exception('Unexpected Fireworks API response format: ' . $responseStr);
		}

		return $data['choices'][0]['message']['content'];
	}//end callFireworksChatAPIWithHistory()
}//end class
