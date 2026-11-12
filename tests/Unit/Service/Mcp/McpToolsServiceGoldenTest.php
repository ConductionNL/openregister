<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * Golden test for McpToolsService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use Exception;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

/**
 * Pins listTools(), callTool(), invokeTool(), addProvider() and getProviders().
 *
 * Each case is a set of scripted providers and a list of operations. Every
 * result (JSON-encoded, so an empty object stays distinct from an empty list),
 * every provider invocation and every log entry are compared with
 * fixtures/mcp-tools-service-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService
 */
class McpToolsServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mcp-tools-service-golden.json';

	/** @var list<array<int, mixed>> Every provider invocation and log entry, in order. */
	private array $calls = [];

	/**
	 * @return array<string, array{0: list<array<string, mixed>>, 1: list<array<int, mixed>>}>
	 */
	public static function cases(): array {
		$or = [
			'app' => 'openregister',
			'tools' => [
				['id' => 'openregister.registers', 'name' => 'registers', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
				['id' => 'openregister.schemas', 'name' => 'schemas'],
				['id' => 'openregister.empty', 'name' => 'empty', 'inputSchema' => ['properties' => []]],
				['id' => 'openregister.nullprops', 'name' => 'nullprops', 'inputSchema' => ['type' => 'custom', 'properties' => null, 'required' => ['x']]],
				['id' => 'openregister.scalar', 'name' => 'scalar', 'inputSchema' => 'not a schema'],
				['id' => 'other.wrong', 'name' => 'wrong'],
				['name' => 'noid'],
				['id' => 'openregisterx.close', 'name' => 'close'],
			],
		];
		$oc = [
			'app' => 'openconnector',
			'tools' => [
				['id' => 'openconnector.sources', 'name' => 'sources'],
				['id' => 'openconnector.registers', 'name' => 'registers'],
				['id' => 'openconnector.boom', 'name' => 'boom'],
				['id' => 'openconnector.typed', 'name' => 'typed'],
			],
			'throws' => ['openconnector.boom' => 'exception', 'openconnector.typed' => 'type'],
		];
		$third = ['app' => 'third', 'tools' => [['id' => 'third.registers', 'name' => 'registers'], ['name' => 'nameless']]];
		$args = ['a' => 1, 'nested' => ['b' => [true, null]]];
		$ops = [
			['list'],
			['call', 'openregister.registers', $args],
			['call', 'registers', []],
			['call', 'sources', $args],
			['call', 'openconnector.registers', []],
			['call', 'boom', $args],
			['call', 'typed', []],
			['call', 'nameless', []],
			['call', 'missing', []],
			['invoke', 'openregister.schemas', $args],
			['invoke', 'registers', []],
			['invoke', 'boom', []],
			['invoke', 'openconnector.typed', $args],
			['invoke', 'missing', []],
			['invoke', 'nameless', []],
			['invoke', '', []],
		];
		return [
			'no providers' => [[], [['list'], ['call', 'x', []], ['invoke', 'x', []], ['providers']]],
			'one provider' => [[$or], $ops],
			'three providers' => [[$or, $oc, $third], $ops],
			'add provider' => [
				[$or],
				[['add', $oc], ['add', ['app' => 'openregister', 'tools' => []]], ['add', $third], ['providers'], ['list'], ['call', 'registers', []]],
			],
		];
	}//end cases()

	/**
	 * @param list<array<string, mixed>> $providers
	 * @param list<array<int, mixed>>    $ops
	 */
	#[DataProvider('cases')]
	public function testOperationsMatchGolden(array $providers, array $ops): void {
		$service = new McpToolsService(array_map(fn (array $spec): IMcpToolProvider => $this->provider($spec), $providers), $this->logger());
		$results = [];
		foreach ($ops as $op) {
			$this->calls[] = ['op', $op[0], $op[1] ?? null];
			$results[] = $this->apply($service, $op);
		}

		$actual = json_decode(json_encode(['results' => $results, 'calls' => $this->calls]), true);
		$name = $this->dataName();
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
	}//end testOperationsMatchGolden()

	/**
	 * @param array<int, mixed> $op
	 */
	private function apply(McpToolsService $service, array $op): mixed {
		try {
			return match ($op[0]) {
				'list' => json_encode($service->listTools()),
				'call' => json_encode($service->callTool($op[1], $op[2])),
				'invoke' => json_encode($service->invokeTool($op[1], $op[2])),
				'add' => $service->addProvider($this->provider($op[1])),
				'providers' => array_map(static fn (IMcpToolProvider $p): string => $p->getAppId(), $service->getProviders()),
			};
		} catch (Exception $e) {
			return ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end apply()

	/**
	 * @param array<string, mixed> $spec
	 */
	private function provider(array $spec): IMcpToolProvider {
		$test = $this;
		return new class($spec, $test) implements IMcpToolProvider {
			public function __construct(private array $spec, private McpToolsServiceGoldenTest $test) {
			}

			public function getAppId(): string {
				return $this->spec['app'];
			}

			public function getTools(): array {
				return $this->spec['tools'];
			}

			public function invokeTool(string $toolId, array $arguments): array {
				$this->test->record(['invoke', $this->spec['app'], $toolId, $arguments]);
				$throws = ($this->spec['throws'][$toolId] ?? null);
				if ($throws === 'exception') {
					throw new Exception('provider failed for ' . $toolId);
				}

				if ($throws === 'type') {
					throw new TypeError('bad argument for ' . $toolId);
				}

				return ['app' => $this->spec['app'], 'tool' => $toolId, 'args' => $arguments, 'empty' => []];
			}
		};
	}//end provider()

	/**
	 * @param array<int, mixed> $call
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					foreach (['providerClass', 'incomingClass', 'existingClass'] as $key) {
						if (isset($context[$key]) === true && str_contains($context[$key], '@anonymous') === true) {
							$context[$key] = 'scripted provider';
						}
					}

					$this->calls[] = ['log', $level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
