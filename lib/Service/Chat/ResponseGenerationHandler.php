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
		$tools = $this->agentTools(agent: $agent, selectedTools: $selectedTools);
		$toolsTime = microtime(true) - $toolsStartTime;

		$llmConfig = $this->settingsService->getLLMSettingsOnly();
		$chatProvider = $this->chatProvider(llmConfig: $llmConfig, hasTools: empty($tools) === false);

		try {
			return $this->generateWithProvider(
				request: [
					'provider' => $chatProvider,
					'config' => $this->buildChatConfig(llmConfig: $llmConfig, agent: $agent),
					'messageHistory' => $this->conversation(
						messageHistory: $messageHistory,
						turn: ['agent' => $agent, 'userMessage' => $userMessage, 'context' => $context, 'cnAiContext' => $cnAiContext]
					),
					'tools' => $tools,
					'channel' => $channel,
				],
				timings: ['start' => $startTime, 'tools' => $toolsTime]
			);
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
	 * Put the system prompt before the history and the user's message after it.
	 *
	 * The system prompt is the agent's prompt or the default, then the app
	 * context and the retrieved RAG context.
	 *
	 * @param array $messageHistory Array of LLPhantMessage objects.
	 * @param array $turn           The agent, the user's message, the RAG context and the app context.
	 *
	 * @return array The full conversation as LLPhantMessage objects.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) LLPhantMessage factory methods are standard LLPhant pattern
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function conversation(array $messageHistory, array $turn): array {
		$systemPrompt = ($turn['agent']?->getPrompt() ?? 'You are a helpful AI assistant that helps users find and understand their data.');
		$systemPrompt .= $this->appContextPrompt(cnAiContext: $turn['cnAiContext']);
		$systemPrompt .= $this->ragContextPrompt(contextText: $turn['context']['text']);
		array_unshift($messageHistory, LLPhantMessage::system($systemPrompt));
		$messageHistory[] = LLPhantMessage::user($turn['userMessage']);
		return $messageHistory;
	}//end conversation()

	/**
	 * Run the chat, log its performance and record its usage.
	 *
	 * Only OllamaChat accumulates token usage today; other providers leave it
	 * empty. The usage is exposed for per-run cost recording (run-analytics).
	 *
	 * @param array                $request Provider, config, conversation, tools and channel.
	 * @param array<string, float> $timings The start time and the tool loading time.
	 *
	 * @return string The generated response.
	 *
	 * @throws Exception When the API call fails.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function generateWithProvider(array $request, array $timings): string {
		$request['functions'] = [];
		if (empty($request['tools']) === false) {
			$request['functions'] = $this->toolHandler->convertToolsToFunctions($request['tools']);
		}

		$llmStartTime = microtime(true);
		$result = $this->runChat(request: $request);
		$llmTime = microtime(true) - $llmStartTime;

		$this->logPerformance(
			request: $request + ['response' => $result['response']],
			timings: ['total' => microtime(true) - $timings['start'], 'tools' => $timings['tools'], 'llm' => $llmTime]
		);

		$this->lastUsage = [];
		if ($result['chat'] instanceof OllamaChat) {
			$this->lastUsage = $result['chat']->lastUsage;
		}

		$this->lastUsage['llmSeconds'] = round($llmTime, 2);
		return $result['response'];
	}//end generateWithProvider()

	/**
	 * The agent's enabled tools, filtered by the selected tools.
	 *
	 * @param Agent|null $agent         Agent configuration.
	 * @param array      $selectedTools Tools selected for this request.
	 *
	 * @return array The tools.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function agentTools(?Agent $agent, array $selectedTools): array {
		$tools = $this->toolHandler->getAgentTools(agent: $agent, selectedTools: $selectedTools);
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

		return $tools;
	}//end agentTools()

	/**
	 * The configured chat provider.
	 *
	 * @param array $llmConfig The LLM settings.
	 * @param bool  $hasTools  Whether the agent has tools enabled, for the log.
	 *
	 * @return mixed The chat provider.
	 *
	 * @throws Exception When no chat provider is configured (code 503).
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function chatProvider(array $llmConfig, bool $hasTools): mixed {
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
				'hasTools' => $hasTools,
			]
		);
		return $chatProvider;
	}//end chatProvider()

	/**
	 * Build the LLPhant configuration for the chat provider.
	 *
	 * Ollama uses its own native config and chat class; OpenAI and Fireworks
	 * (an OpenAI-compatible API) use OpenAIConfig.
	 *
	 * @param array      $llmConfig The LLM settings.
	 * @param Agent|null $agent     Agent configuration, for its model and temperature.
	 *
	 * @return OllamaConfig|OpenAIConfig The provider configuration.
	 *
	 * @throws Exception When the provider is unsupported or misses its URL or API key.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function buildChatConfig(array $llmConfig, ?Agent $agent): OllamaConfig|OpenAIConfig {
		$chatProvider = $llmConfig['chatProvider'];
		$config = match ($chatProvider) {
			'ollama' => $this->ollamaConfig(ollamaConfig: ($llmConfig['ollamaConfig'] ?? []), agent: $agent),
			'openai' => $this->openAIConfig(openaiConfig: ($llmConfig['openaiConfig'] ?? []), agent: $agent),
			'fireworks' => $this->fireworksConfig(fireworksConfig: ($llmConfig['fireworksConfig'] ?? []), agent: $agent),
			default => throw new Exception("Unsupported chat provider: {$chatProvider}"),
		};

		// Through modelOptions, NOT a property: the chat classes read
		// $config->modelOptions and send it as the API arguments, and nothing
		// in LLPhant reads a temperature property.
		if ($agent?->getTemperature() !== null) {
			$config->modelOptions['temperature'] = $agent->getTemperature();
		}

		return $config;
	}//end buildChatConfig()

	/**
	 * Build the native Ollama configuration.
	 *
	 * @param array      $ollamaConfig The Ollama settings.
	 * @param Agent|null $agent        Agent configuration, for its model.
	 *
	 * @return OllamaConfig The configuration.
	 *
	 * @throws Exception When the Ollama URL is not configured.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function ollamaConfig(array $ollamaConfig, ?Agent $agent): OllamaConfig {
		if (empty($ollamaConfig['url']) === true) {
			throw new Exception('Ollama URL is not configured');
		}

		$config = new OllamaConfig();
		$config->url = rtrim($ollamaConfig['url'], '/') . '/api/';
		$config->model = $this->chatModel(agent: $agent, default: ($ollamaConfig['chatModel'] ?? 'llama2'));
		return $config;
	}//end ollamaConfig()

	/**
	 * Build the OpenAI configuration.
	 *
	 * LLPhant has no organization support of any kind, so no organization id is set.
	 *
	 * @param array      $openaiConfig The OpenAI settings.
	 * @param Agent|null $agent        Agent configuration, for its model.
	 *
	 * @return OpenAIConfig The configuration.
	 *
	 * @throws Exception When the OpenAI API key is not configured.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function openAIConfig(array $openaiConfig, ?Agent $agent): OpenAIConfig {
		$config = new OpenAIConfig();
		if (empty($openaiConfig['apiKey']) === true) {
			throw new Exception('OpenAI API key is not configured', 503);
		}

		$config->apiKey = $openaiConfig['apiKey'];
		$config->model = $this->chatModel(agent: $agent, default: ($openaiConfig['chatModel'] ?? 'gpt-4o-mini'));
		return $config;
	}//end openAIConfig()

	/**
	 * Build the configuration for the OpenAI-compatible Fireworks AI API.
	 *
	 * @param array      $fireworksConfig The Fireworks settings.
	 * @param Agent|null $agent           Agent configuration, for its model.
	 *
	 * @return OpenAIConfig The configuration, with the base URL ending in /v1.
	 *
	 * @throws Exception When the Fireworks API key is not configured.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function fireworksConfig(array $fireworksConfig, ?Agent $agent): OpenAIConfig {
		$config = new OpenAIConfig();
		if (empty($fireworksConfig['apiKey']) === true) {
			throw new Exception('Fireworks AI API key is not configured', 503);
		}

		$config->apiKey = $fireworksConfig['apiKey'];
		$config->model = $this->chatModel(
			agent: $agent,
			default: ($fireworksConfig['chatModel'] ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct')
		);

		$baseUrl = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
		if (str_ends_with($baseUrl, '/v1') === false) {
			$baseUrl .= '/v1';
		}

		$config->url = $baseUrl;
		return $config;
	}//end fireworksConfig()

	/**
	 * The agent's model when it has one, otherwise the configured model.
	 *
	 * @param Agent|null $agent   Agent configuration.
	 * @param mixed      $default The model from the provider settings.
	 *
	 * @return mixed The model to use.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function chatModel(?Agent $agent, mixed $default): mixed {
		$agentModel = $agent?->getModel();
		if (empty($agentModel) === false) {
			return $agentModel;
		}

		return $default;
	}//end chatModel()

	/**
	 * Render the CnAiContext snapshot the widget sends with each message for the system prompt.
	 *
	 * Without it the LLM has no idea which app the user is in, and calls
	 * another app's tools. The snapshot is small and free-form (typically
	 * {app, slug, view, objectId}); it is rendered as a bullet list so the
	 * model can quote individual fields.
	 *
	 * @param array $cnAiContext The app context snapshot.
	 *
	 * @return string The prompt section, or an empty string without a snapshot.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function appContextPrompt(array $cnAiContext): string {
		if (empty($cnAiContext) === true) {
			return '';
		}

		$prompt = "\n\nCURRENT APP CONTEXT (this is where the user is RIGHT NOW — prefer tools that match this app):\n";
		foreach ($cnAiContext as $key => $value) {
			if (is_scalar($value) === true) {
				$prompt .= "- {$key}: " . (string)$value . "\n";
				continue;
			}

			$prompt .= "- {$key}: " . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
		}

		return $prompt;
	}//end appContextPrompt()

	/**
	 * Render the retrieved RAG context for the system prompt.
	 *
	 * @param mixed $contextText The retrieved context text.
	 *
	 * @return string The prompt section, or an empty string without context.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function ragContextPrompt(mixed $contextText): string {
		if (empty($contextText) === true) {
			return '';
		}

		$prompt = "\n\nUse the following context to answer the user's question:\n\n";
		$prompt .= "CONTEXT:\n" . $contextText . "\n\n";
		$prompt .= "If the context doesn't contain relevant information, say so honestly. ";
		return $prompt . 'Always cite which sources you used when answering.';
	}//end ragContextPrompt()

	/**
	 * Send the conversation to the provider's chat client.
	 *
	 * Ollama runs through OllamaChat; OpenAI and the OpenAI-compatible Fireworks
	 * API run through OpenAIChat (OpenAIChat::__construct() type-errors when
	 * given an OllamaConfig).
	 *
	 * @param array $request Provider, config, message history, tools, functions and channel.
	 *
	 * @return array{response: string, chat: OpenAIChat|OllamaChat} The response and the chat client used.
	 *
	 * @throws Exception When the API call fails.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function runChat(array $request): array {
		$chat = match ($request['provider']) {
			'ollama' => new OllamaChat($request['config']),
			default => new OpenAIChat($request['config']),
		};

		return ['response' => $this->invokeChatWithTools(chat: $chat, request: $request), 'chat' => $chat];
	}//end runChat()

	/**
	 * Register the request's functions as tools on the chat client and invoke it.
	 *
	 * @param OpenAIChat|OllamaChat $chat    The chat client.
	 * @param array                 $request Provider, message history, tools, functions and channel.
	 *
	 * @return string The assistant's textual response.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function invokeChatWithTools(OpenAIChat|OllamaChat $chat, array $request): string {
		if (empty($request['functions']) === false) {
			$functionInfoObjects = $this->toolHandler->convertFunctionsToFunctionInfo(
				functions: $request['functions'],
				tools: $request['tools']
			);
			$chat->setTools(
				$this->wrapToolsForStreaming(functionInfoObjects: $functionInfoObjects, channel: $request['channel'])
			);
		}

		return $this->invokeChat(
			chat: $chat,
			messageHistory: $request['messageHistory'],
			channel: $request['channel'],
			provider: $request['provider']
		);
	}//end invokeChatWithTools()

	/**
	 * Log the provider, model, response length and timings of a generated response.
	 *
	 * @param array                $request The chat request with the response.
	 * @param array<string, float> $timings Total, tools and LLM time in seconds.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function logPerformance(array $request, array $timings): void {
		$this->logger->info(
			message: '[ResponseGenerationHandler] Response generated - PERFORMANCE',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $request['provider'],
				'model' => $request['config']->model,
				'responseLength' => strlen($request['response']),
				'timings' => [
					'total' => round($timings['total'], 2) . 's',
					'toolsLoading' => round($timings['tools'], 3) . 's',
					'llmGeneration' => round($timings['llm'], 2) . 's',
					'overhead' => round($timings['total'] - $timings['llm'] - $timings['tools'], 3) . 's',
				],
			]
		);
	}//end logPerformance()

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
		// for providers whose streaming surface throws MissingFeatureException, and
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
}//end class
