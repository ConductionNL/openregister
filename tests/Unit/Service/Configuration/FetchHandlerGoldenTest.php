<?php

/**
 * Golden: what the configuration fetch handler answers and logs for every
 * kind of remote source and body, pinned on exact arrays and messages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\FetchHandler
 */
class FetchHandlerGoldenTest extends TestCase {
	/**
	 * What was logged: level, message and the context without file and line.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A handler whose client answers with the response, or throws the throwable.
	 *
	 * @param Response|\Throwable $answer The response or what the call throws.
	 *
	 * @return FetchHandler
	 */
	private function handler(Response|\Throwable $answer): FetchHandler {
		$client = $this->createMock(Client::class);
		if ($answer instanceof Response) {
			$client->method('request')->with('GET', 'https://example.test/c')->willReturn($answer);
		} else {
			$client->method('request')->willThrowException($answer);
		}

		$test   = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * @param FetchHandlerGoldenTest $test The test that keeps the log.
			 */
			public function __construct(private FetchHandlerGoldenTest $test) {
			}

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->test->keep($level, (string) $message, $context);
			}
		};

		return new FetchHandler($client, $logger);
	}//end handler()

	/**
	 * Keep one log line.
	 *
	 * @param mixed  $level   The level.
	 * @param string $message The message.
	 * @param array  $context The context.
	 *
	 * @return void
	 */
	public function keep(mixed $level, string $message, array $context): void {
		$this->assertSame(__DIR__, dirname(str_replace('/lib/Service/Configuration', '/tests/Unit/Service/Configuration', (string) $context['file'])));
		$this->assertIsInt($context['line']);
		unset($context['file'], $context['line']);
		$this->logged[] = [$level, $message, $context];
	}//end keep()

	/**
	 * An answer as status and data.
	 *
	 * @param array|JSONResponse $answer The handler's answer.
	 *
	 * @return array
	 */
	private function shape(array|JSONResponse $answer): array {
		if ($answer instanceof JSONResponse) {
			return ['status' => $answer->getStatus(), 'data' => $answer->getData()];
		}

		return ['array' => $answer];
	}//end shape()

	/**
	 * Bodies by Content-Type: what is answered and what is logged.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array, 3: array}>
	 */
	public static function bodies(): array {
		$url     = 'https://example.test/c';
		$fetch   = ['debug', '[FetchHandler] Fetching data from URL: '.$url, []];
		$fetched = ['debug', '[FetchHandler] Successfully fetched and parsed data from URL: '.$url, []];
		return [
			'json'               => ['application/json', '{"a":{"b":1}}', ['array' => ['a' => ['b' => 1]]], [$fetch, $fetched]],
			'yaml as json'       => ['application/json', "a: b\n", ['array' => ['a' => 'b']], [$fetch, ['info', '[FetchHandler] Content-Type was JSON but data was successfully parsed as YAML', []], $fetched]],
			'yaml'               => ['application/x-yaml', "a: [1, 2]\n", ['array' => ['a' => [1, 2]]], [$fetch, $fetched]],
			'json as yml'        => ['text/yml', '{"a": 1}', ['array' => ['a' => 1]], [$fetch, $fetched]],
			'plain json'         => ['text/plain', '[1,2]', ['array' => [1, 2]], [$fetch, $fetched]],
			'no type'            => ['', "k: v\n", ['array' => ['k' => 'v']], [$fetch, $fetched]],
			'scalar'             => ['text/plain', '"text"', ['status' => 400, 'data' => ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => 'text/plain']], [$fetch, ['error', '[FetchHandler] Failed to parse response body as JSON or YAML', ['Content-Type' => 'text/plain', 'url' => $url]]]],
			'broken yaml'        => ['application/yaml', "a: [unclosed\n", ['status' => 400, 'data' => ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => 'application/yaml']], [$fetch, ['warning', '[FetchHandler] Body is neither JSON nor YAML: Malformed inline YAML string at line 2.', ['Content-Type' => 'application/yaml']], ['error', '[FetchHandler] Failed to parse response body as JSON or YAML', ['Content-Type' => 'application/yaml', 'url' => $url]]]],
			'broken under json'  => ['application/json', "a: [unclosed\n", ['status' => 400, 'data' => ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => 'application/json']], [$fetch, ['warning', '[FetchHandler] Body is neither JSON nor YAML: Malformed inline YAML string at line 2.', ['Content-Type' => 'application/json']], ['error', '[FetchHandler] Failed to parse response body as JSON or YAML', ['Content-Type' => 'application/json', 'url' => $url]]]],
		];
	}//end bodies()

	/**
	 * A body is decoded by its Content-Type, and logged.
	 *
	 * @param string $type     The Content-Type.
	 * @param string $body     The body.
	 * @param array  $expected The answer.
	 * @param array  $log      The log.
	 *
	 * @return void
	 *
	 * @dataProvider bodies
	 */
	public function testGetJsonFromUrl(string $type, string $body, array $expected, array $log): void {
		$headers = [];
		if ($type !== '') {
			$headers['Content-Type'] = $type;
		}

		$handler = $this->handler(new Response(200, $headers, $body));
		$this->assertSame($expected, $this->shape($handler->getJSONfromURL('https://example.test/c')));
		$this->assertSame($log, $this->logged);
	}//end testGetJsonFromUrl()

	/**
	 * A failing call answers 400 and logs the error.
	 *
	 * @return void
	 */
	public function testGetJsonFromUrlFails(): void {
		$handler = $this->handler(new TransferException('refused'));
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Failed to do a GET api-call on url: https://example.test/c refused']],
			$this->shape($handler->getJSONfromURL('https://example.test/c'))
		);
		$this->assertSame(
			[
				['debug', '[FetchHandler] Fetching data from URL: https://example.test/c', []],
				['error', '[FetchHandler] Failed to do a GET api-call on url: https://example.test/c refused', []],
			],
			$this->logged
		);
	}//end testGetJsonFromUrlFails()

	/**
	 * A configuration.
	 *
	 * @param string|null $type The source type.
	 * @param string|null $url  The source url.
	 *
	 * @return Configuration
	 */
	private function configuration(?string $type, ?string $url): Configuration {
		$configuration = new Configuration();
		$configuration->setSourceType($type);
		$configuration->setSourceUrl($url);

		return $configuration;
	}//end configuration()

	/**
	 * Only a remote source with a url is fetched; the fetched counts are logged.
	 *
	 * @return void
	 */
	public function testFetchRemoteConfiguration(): void {
		$handler = $this->handler(new Response(200, ['Content-Type' => 'application/json'], '{"components":{"schemas":{"a":{},"b":{}},"registers":{"r":{}}}}'));
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Configuration is not from a remote source']],
			$this->shape($handler->fetchRemoteConfiguration($this->configuration('local', 'https://example.test/c')))
		);
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Configuration has no source URL']],
			$this->shape($handler->fetchRemoteConfiguration($this->configuration('url', '')))
		);
		$this->assertSame([], $this->logged);
		$this->assertSame(
			['array' => ['components' => ['schemas' => ['a' => [], 'b' => []], 'registers' => ['r' => []]]]],
			$this->shape($handler->fetchRemoteConfiguration($this->configuration('github', 'https://example.test/c')))
		);
		$this->assertSame(
			[
				['info', '[FetchHandler] Fetching remote configuration from: https://example.test/c', []],
				['debug', '[FetchHandler] Fetching data from URL: https://example.test/c', []],
				['debug', '[FetchHandler] Successfully fetched and parsed data from URL: https://example.test/c', []],
				['info', '[FetchHandler] Fetched config: 2 schemas, 1 registers', []],
			],
			$this->logged
		);
	}//end testFetchRemoteConfiguration()

	/**
	 * A remote fetch that answers an error passes it on; one without components counts zero.
	 *
	 * @return void
	 */
	public function testFetchRemoteConfigurationPassesErrors(): void {
		$handler = $this->handler(new TransferException('refused'));
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Failed to do a GET api-call on url: https://example.test/c refused']],
			$this->shape($handler->fetchRemoteConfiguration($this->configuration('gitlab', 'https://example.test/c')))
		);
		$this->assertCount(3, $this->logged);

		$this->logged = [];
		$handler      = $this->handler(new Response(200, [], '{"x":1}'));
		$this->assertSame(['array' => ['x' => 1]], $this->shape($handler->fetchRemoteConfiguration($this->configuration('url', 'https://example.test/c'))));
		$this->assertSame(['info', '[FetchHandler] Fetched config: 0 schemas, 0 registers', []], $this->logged[3]);
	}//end testFetchRemoteConfigurationPassesErrors()

	/**
	 * An exception while fetching answers 500, with its own message for Guzzle and for anything else.
	 *
	 * @return void
	 */
	public function testFetchRemoteConfigurationExceptions(): void {
		$cases = [
			[new \GuzzleHttp\Exception\InvalidArgumentException('bad uri'), 'Failed to fetch remote configuration: bad uri', '[FetchHandler] Failed to fetch remote configuration: bad uri'],
			[new Exception('boom'), 'Unexpected error: boom', '[FetchHandler] Unexpected error fetching remote configuration: boom'],
		];
		foreach ($cases as [$thrown, $answer, $logged]) {
			$this->logged = [];
			$response     = $this->createMock(Response::class);
			$response->method('getBody')->willThrowException($thrown);
			$client = $this->createMock(Client::class);
			$client->method('request')->willReturn($response);
			$handler = new FetchHandler($client, $this->loggerOf());
			$this->assertSame(
				['status' => 500, 'data' => ['error' => $answer]],
				$this->shape($handler->fetchRemoteConfiguration($this->configuration('url', 'https://example.test/c')))
			);
			$this->assertSame(['error', $logged, []], end($this->logged));
		}
	}//end testFetchRemoteConfigurationExceptions()

	/**
	 * The logger the handler() helper builds, on its own.
	 *
	 * @return \Psr\Log\LoggerInterface
	 */
	private function loggerOf(): \Psr\Log\LoggerInterface {
		$reflection = new \ReflectionProperty(FetchHandler::class, 'logger');
		return $reflection->getValue($this->handler(new Response(200)));
	}//end loggerOf()
}//end class
