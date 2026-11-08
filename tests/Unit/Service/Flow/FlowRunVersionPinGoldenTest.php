<?php

/**
 * Golden test for the published version a new run is pinned to.
 *
 * Every resolution and refusal path of FlowRunVersionPin, with the calls it
 * makes, replayed against a recording. Record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowRunVersionPin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunVersionPin
 * @uses   \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused
 * @uses   \OCA\OpenRegister\Service\Flow\FlowDeadEnd
 * @uses   \OCA\OpenRegister\Service\Flow\FlowDeadEndSteps
 * @uses   \OCA\OpenRegister\Service\Flow\FlowDeadEndVerdict
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Db\FlowVersion
 */
class FlowRunVersionPinGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/flow-run-version-pin-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $golden = null;

	/** @var array<string, mixed> */
	private static array $recorded = [];

	/**
	 * Dispatch situations.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$deadEnd = ['warnings' => [
			['reason' => 'node-dead-end', 'step' => 'b'],
			['reason' => 'other', 'step' => 'c'],
			['reason' => 'node-dead-end'],
			['step' => 'd'],
		]];
		$base = ['trigger' => 'manual', 'version' => true, 'graph' => ['nodes' => [['id' => 'a']], 'edges' => [['from' => 'a']]], 'flow' => 'ok', 'findings' => ['warnings' => []]];
		$cases = [
			'version mapper unresolvable' => ['versionMapper' => 'throw'],
			'version mapper unresolvable, test run' => ['versionMapper' => 'throw', 'trigger' => 'test'],
			'version mapper of the wrong type' => ['versionMapper' => 'wrong'],
			'reading the published version fails' => ['findPublished' => 'throw'],
			'nothing published, test run' => ['version' => false, 'trigger' => 'test'],
			'nothing published, manual' => ['version' => false],
			'nothing published, schedule' => ['version' => false, 'trigger' => 'schedule'],
			'definition unreadable' => ['graph' => 'throw'],
			'definition missing' => ['graph' => null],
			'flow mapper unresolvable' => ['flowMapper' => 'throw'],
			'preflight unresolvable' => ['preflight' => 'throw'],
			'flow not found' => ['flow' => 'throw'],
			'sound, flow ok' => [],
			'sound, flow in error is cleared' => ['flow' => 'error'],
			'sound, flow with no status' => ['flow' => null],
			'sound, findings without warnings' => ['findings' => []],
			'sound, graph without nodes or edges' => ['graph' => ['other' => 1]],
			'dead end' => ['findings' => $deadEnd],
			'dead end, flow already in error' => ['findings' => $deadEnd, 'flow' => 'error'],
			'dead end, test run of a published flow' => ['findings' => $deadEnd, 'trigger' => 'test'],
		];

		return array_map(static fn (array $case): array => [$case + $base], $cases);
	}

	/**
	 * @param array<string, mixed> $case The situation.
	 */
	#[DataProvider('cases')]
	public function testDispatchMatchesTheRecording(array $case): void {
		$pin = new FlowRunVersionPin($this->container(case: $case), $this->logger());
		try {
			$version = $pin->requirePublishedAndSound(flowId: 'flow-1', trigger: $case['trigger']);
			$outcome = ['version' => $version?->getVersion()];
		} catch (Throwable $e) {
			$outcome = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$this->compare(actual: ['outcome' => $outcome, 'calls' => $this->calls]);
	}

	/**
	 * The graph of a version, and of none.
	 */
	public function testGraphOfMatchesTheRecording(): void {
		$pin = new FlowRunVersionPin($this->container(case: ['graph' => ['nodes' => []]]), $this->logger());
		$actual = ['none' => $pin->graphOf(version: null), 'one' => $pin->graphOf(version: $this->version())];
		$this->compare(actual: ['outcome' => $actual, 'calls' => $this->calls]);
	}

	/**
	 * Compare with, or record into, the golden file.
	 *
	 * @param array<string, mixed> $actual The observed behaviour.
	 */
	private function compare(array $actual): void {
		$name = $this->name() . ' ' . (string)$this->dataName();
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->assertTrue(true);
			return;
		}

		self::$golden ??= json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertArrayHasKey($name, self::$golden);
		$this->assertSame(self::$golden[$name], $actual);
	}

	private function version(): FlowVersion {
		$version = new FlowVersion();
		$version->setFlowUuid('flow-1');
		$version->setVersion(3);
		$version->setDefinitionHash('hash-3');
		return $version;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				unset($context['file'], $context['line']);
				$this->calls[] = ['log.' . $level => $message, 'context' => $context];
			});
		}

		return $logger;
	}

	/**
	 * @param array<string, mixed> $case The situation.
	 */
	private function container(array $case): ContainerInterface {
		$services = [
			\OCA\OpenRegister\Db\FlowVersionMapperInterface::class => fn () => $this->versionMapper(case: $case),
			FlowDefinitionPin::class => fn () => $this->definitions(case: $case),
			'OCA\OpenRegister\Db\FlowMapper' => fn () => $this->flows(case: $case),
			'OCA\OpenRegister\Service\Flow\FlowNodePreflight' => fn () => $this->preflight(case: $case),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($services, $case): mixed {
			$this->calls[] = ['container.get' => $id];
			$key = ['FlowVersionMapper' => 'versionMapper', 'FlowMapper' => 'flowMapper', 'FlowNodePreflight' => 'preflight'][substr($id, (int)strrpos($id, '\\') + 1)] ?? null;
			if ($key !== null && ($case[$key] ?? null) === 'throw') {
				throw new RuntimeException('no ' . $id);
			}

			if ($key === 'versionMapper' && ($case[$key] ?? null) === 'wrong') {
				return new stdClass();
			}

			return $services[$id]();
		});
		return $container;
	}

	/**
	 * @param array<string, mixed> $case The situation.
	 */
	private function versionMapper(array $case): FlowVersionMapper {
		$mapper = $this->createMock(FlowVersionMapper::class);
		$mapper->method('findPublished')->willReturnCallback(function (string $flowUuid) use ($case): ?FlowVersion {
			$this->calls[] = ['versions.findPublished' => $flowUuid];
			if (($case['findPublished'] ?? null) === 'throw') {
				throw new RuntimeException('database gone');
			}

			return ($case['version'] ?? false) === true ? $this->version() : null;
		});
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case The situation.
	 */
	private function definitions(array $case): FlowDefinitionPin {
		$pin = $this->createMock(FlowDefinitionPin::class);
		$pin->method('graphFor')->willReturnCallback(function (?string $hash) use ($case): ?array {
			$this->calls[] = ['definitions.graphFor' => $hash];
			if (($case['graph'] ?? null) === 'throw') {
				throw new RuntimeException('definition unreadable');
			}

			return $case['graph'];
		});
		return $pin;
	}

	/**
	 * @param array<string, mixed> $case The situation.
	 */
	private function flows(array $case): FlowMapper {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(function (string $uuid) use ($case): Flow {
			$this->calls[] = ['flows.findByUuid' => $uuid];
			if ($case['flow'] === 'throw') {
				throw new RuntimeException('no flow');
			}

			$flow = new Flow();
			$flow->setStatus($case['flow']);
			$flow->setStatusMessage('earlier message');
			return $flow;
		});
		$mapper->method('update')->willReturnCallback(function (Flow $flow): Flow {
			$this->calls[] = ['flows.update' => ['status' => $flow->getStatus(), 'message' => $flow->getStatusMessage()]];
			return $flow;
		});
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case The situation.
	 */
	private function preflight(array $case): FlowNodePreflight {
		$preflight = $this->createMock(FlowNodePreflight::class);
		$preflight->method('inspect')->willReturnCallback(function (array $flow) use ($case): array {
			$this->calls[] = ['preflight.inspect' => $flow];
			return $case['findings'];
		});
		return $preflight;
	}
}
