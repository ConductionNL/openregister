<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Chat;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\Message as LLPhantMessage;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tool\StreamingToolInstanceWrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for ResponseGenerationHandler::generateResponse().
 *
 * The chat APIs are a local `php -S` stub (fixtures/llm-stub-router.php) that
 * records every request. Each case is an LLM configuration, an agent and a
 * request; the response or the exception, the requests the stub received, the
 * tool handler calls and the log entries (without file, line and timings) are
 * compared with fixtures/response-generation-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class ResponseGenerationGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/response-generation-golden.json';

	/** @var resource|null The stub server process. */
	private static $server = null;

	private static string $base = '';

	private static string $requestLog = '';

	/** @var list<array<int, mixed>> Tool handler calls and log entries, in order. */
	private array $calls = [];

	public static function setUpBeforeClass(): void {
		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
		fclose($probe);

		self::$requestLog = (string)tempnam(sys_get_temp_dir(), 'llm-stub');
		self::$base = 'http://127.0.0.1:' . $port;
		$env = array_merge(getenv(), ['LLM_STUB_LOG' => self::$requestLog]);
		self::$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/llm-stub-router.php'],
			[['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
			$pipes,
			null,
			$env
		);
		for ($i = 0; $i < 100; $i++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
			if ($socket !== false) {
				fclose($socket);
				break;
			}

			usleep(50000);
		}

		putenv('OPENAI_BASE_URL=' . self::$base . '/v1');
	}//end setUpBeforeClass()

	public static function tearDownAfterClass(): void {
		if (is_resource(self::$server) === true) {
			proc_terminate(self::$server);
			proc_close(self::$server);
		}

		putenv('OPENAI_BASE_URL');
		@unlink(self::$requestLog);
	}//end tearDownAfterClass()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$openai = ['chatProvider' => 'openai', 'openaiConfig' => ['apiKey' => 'sk-test', 'chatModel' => 'gpt-test']];
		$ollama = ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => '{base}/', 'chatModel' => 'llama-test']];
		$fireworks = ['chatProvider' => 'fireworks', 'fireworksConfig' => ['apiKey' => 'fw-test', 'chatModel' => 'fw-model', 'baseUrl' => '{base}']];
		$agent = ['model' => 'agent-model', 'temperature' => 0.3, 'prompt' => 'You are the register guide.'];
		return [
			'no provider' => [['llm' => []]],
			'empty provider' => [['llm' => ['chatProvider' => '']]],
			'unsupported provider' => [['llm' => ['chatProvider' => 'mistral']]],
			'openai without key' => [['llm' => ['chatProvider' => 'openai', 'openaiConfig' => []]]],
			'fireworks without key' => [['llm' => ['chatProvider' => 'fireworks']]],
			'ollama without url' => [['llm' => ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => '']]]],
			'openai no agent' => [['llm' => $openai]],
			'openai default model' => [['llm' => ['chatProvider' => 'openai', 'openaiConfig' => ['apiKey' => 'sk-test']]]],
			'openai agent' => [['llm' => $openai, 'agent' => $agent]],
			'openai agent empty model' => [['llm' => $openai, 'agent' => ['model' => '', 'temperature' => null]]],
			'openai context and app' => [[
				'llm' => $openai,
				'context' => 'Register A holds 3 schemas.',
				'cnAiContext' => ['app' => 'openregister', 'objectId' => 42, 'flags' => ['a' => true], 'none' => null],
				'history' => ['Earlier question', 'Earlier answer'],
			]],
			'openai error' => [['llm' => ['chatProvider' => 'openai', 'openaiConfig' => ['apiKey' => 'sk', 'chatModel' => 'model-error']]]],
			'openai tools' => [['llm' => $openai, 'agent' => $agent, 'tools' => true]],
			'ollama no agent' => [['llm' => $ollama]],
			'ollama default model' => [['llm' => ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => '{base}']]]],
			'ollama agent' => [['llm' => $ollama, 'agent' => $agent, 'context' => 'Some context']],
			'ollama tools with channel' => [['llm' => $ollama, 'agent' => $agent, 'tools' => true, 'channel' => true]],
			'ollama error' => [['llm' => ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => '{base}', 'chatModel' => 'model-error']]]],
			'fireworks' => [['llm' => $fireworks]],
			'fireworks agent' => [['llm' => $fireworks, 'agent' => $agent]],
			'fireworks default url and model' => [['llm' => ['chatProvider' => 'fireworks', 'fireworksConfig' => ['apiKey' => 'fw', 'baseUrl' => '{base}/v1/']]]],
			'fireworks error' => [['llm' => ['chatProvider' => 'fireworks', 'fireworksConfig' => ['apiKey' => 'fw', 'chatModel' => 'model-error', 'baseUrl' => '{base}']]]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testGenerateResponseMatchesGolden(array $case): void {
		$name = $this->dataName();
		file_put_contents(self::$requestLog, '');

		$agent = null;
		if (isset($case['agent']) === true) {
			$agent = new Agent();
			$agent->setModel($case['agent']['model'] ?? null);
			$agent->setTemperature($case['agent']['temperature'] ?? null);
			$agent->setPrompt($case['agent']['prompt'] ?? null);
		}

		$history = [];
		foreach (($case['history'] ?? []) as $index => $text) {
			$history[] = ($index % 2 === 0) ? LLPhantMessage::user($text) : LLPhantMessage::assistant($text);
		}

		$functionInfos = [];
		$channel = null;
		if (($case['channel'] ?? false) === true) {
			$channel = new StreamYieldChannel();
		}

		$handler = $this->handler(case: $case, functionInfos: $functionInfos);
		try {
			$actual = ['response' => $handler->generateResponse(
				'What is in register A?',
				['text' => ($case['context'] ?? ''), 'sources' => []],
				$history,
				$agent,
				['tool-a'],
				$channel,
				($case['cnAiContext'] ?? [])
			)];
			$usage = $handler->lastUsage;
			unset($usage['llmSeconds']);
			$actual['usage'] = $usage;
		} catch (\Throwable $e) {
			$actual = [
				'threw' => get_class($e),
				'message' => str_replace(self::$base, '{base}', $e->getMessage()),
				'code' => $e->getCode(),
			];
		}

		$requests = [];
		foreach (array_filter(explode("\n", (string)file_get_contents(self::$requestLog))) as $line) {
			$requests[] = json_decode($line, true);
		}

		$actual['requests'] = $requests;
		$actual['calls'] = $this->calls;
		$actual['instances'] = array_map(static fn (FunctionInfo $info): string => ($info->instance instanceof StreamingToolInstanceWrapper) ? 'wrapped' : 'plain', $functionInfos);
		$actual = json_decode(str_replace(addcslashes(self::$base, '/'), '{base}', (string)json_encode($actual)), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testGenerateResponseMatchesGolden()

	/**
	 * One message to Fireworks is one request to the provider, as for every other provider.
	 */
	public function testFireworksSendsOneRequestPerMessage(): void {
		file_put_contents(self::$requestLog, '');
		$functionInfos = [];
		$case = ['llm' => ['chatProvider' => 'fireworks', 'fireworksConfig' => ['apiKey' => 'fw', 'chatModel' => 'fw-model', 'baseUrl' => '{base}']]];
		$response = $this->handler(case: $case, functionInfos: $functionInfos)
			->generateResponse('Hello', ['text' => '', 'sources' => []], [], null);

		$requests = array_values(array_filter(explode("\n", (string)file_get_contents(self::$requestLog))));
		$this->assertCount(1, $requests);
		$this->assertSame('Answer from /v1/chat/completions', $response);
	}//end testFireworksSendsOneRequestPerMessage()

	/**
	 * @param array<string, mixed> $case
	 * @param list<FunctionInfo>   $functionInfos Filled with the FunctionInfo objects handed to the chat.
	 */
	private function handler(array $case, array &$functionInfos): ResponseGenerationHandler {
		$llm = json_decode(str_replace('{base}', self::$base, (string)json_encode($case['llm'])), true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLLMSettingsOnly')->willReturn($llm);

		$tool = new class {
			public function getName(): string {
				return 'tool-a';
			}

			public function lookup(string $id): string {
				return 'found ' . $id;
			}
		};

		$tools = $this->createMock(ToolManagementHandler::class);
		$tools->method('getAgentTools')->willReturnCallback(
			function ($agent, array $selected) use ($case, $tool): array {
				$this->calls[] = ['getAgentTools', $agent !== null, $selected];
				return (($case['tools'] ?? false) === true) ? [$tool] : [];
			}
		);
		$tools->method('convertToolsToFunctions')->willReturnCallback(
			function (array $given): array {
				$this->calls[] = ['convertToolsToFunctions', count($given)];
				return [['name' => 'lookup', 'description' => 'Look up an object', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]];
			}
		);
		$tools->method('convertFunctionsToFunctionInfo')->willReturnCallback(
			function (array $functions, array $given) use ($tool, &$functionInfos): array {
				$this->calls[] = ['convertFunctionsToFunctionInfo', $functions, count($given)];
				$functionInfos = [new FunctionInfo('lookup', $tool, 'Look up an object', [])];
				return $functionInfos;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['timings']);
					$this->calls[] = ['log', $level, $message, $context];
				}
			);
		}

		return new ResponseGenerationHandler(settingsService: $settings, toolHandler: $tools, logger: $logger);
	}//end handler()
}//end class
