<?php

/**
 * Golden test for LifecycleActionExecutor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Lifecycle\TransitionPayload;

/**
 * Golden test for LifecycleActionExecutor::run().
 *
 * Every handler appends its name to the payload's `trail` and records the
 * arguments it was called with; the registry and logger write to the same
 * trace. Each scenario's resulting payload, or its refusal, and the trace
 * were recorded from the code as it stood before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor
 */
class LifecycleActionExecutorGoldenTest extends TestCase {

	/**
	 * Registry, handler and logger calls, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	private function executor(): LifecycleActionExecutor {
		$registry = $this->createMock(LifecycleActionRegistry::class);
		$registry->method('resolve')->willReturnCallback(
			function (string $name): LifecycleActionInterface {
				$this->trace[] = 'resolve ' . $name;
				if ($name === 'ghost') {
					throw new RuntimeException('no handler for ghost');
				}

				return $this->handler();
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->trace[] = 'debug ' . $message;
			}
		);
		return new LifecycleActionExecutor($registry, $logger);
	}//end executor()

	private function handler(): LifecycleActionInterface {
		$handler = $this->createMock(LifecycleActionInterface::class);
		$handler->method('execute')->willReturnCallback(
			function (array $objectData, array $previousData, array $parameters, string $actionName): array {
				$this->trace[] = 'execute ' . $actionName . ' ' . json_encode([$objectData, $previousData, $parameters]);
				$objectData['trail'][] = $actionName;
				return $objectData;
			}
		);
		return $handler;
	}//end handler()

	private function outcome(array $actions): string {
		$this->trace = [];
		try {
			$result = $this->executor()->run(
				$actions,
				new TransitionPayload('close', ['status' => 'closed', 'kind' => 'A', 'trail' => []], ['status' => 'open', 'kind' => 'B'])
			);
			return json_encode($result);
		} catch (RuntimeException $exception) {
			return '!' . $exception->getMessage();
		}
	}//end outcome()

	public function testRunMatchesTheRecordedTranscripts(): void {
		$prev = '{"status":"open","kind":"B"}';
		$cases = [
			'no actions' => [[], '{"status":"closed","kind":"A","trail":[]}', []],
			'threads payload and parameters' => [
				[
					['action' => 'first', 'actionParameters' => ['x' => 1]],
					['action' => 'second', 'actionParameters' => 'not-an-array'],
				],
				'{"status":"closed","kind":"A","trail":["first","second"]}',
				[
					'resolve first',
					'execute first [{"status":"closed","kind":"A","trail":[]},' . $prev . ',{"x":1}]',
					'debug [LifecycleActionExecutor] ran action "first" on transition "close".',
					'resolve second',
					'execute second [{"status":"closed","kind":"A","trail":["first"]},' . $prev . ',[]]',
					'debug [LifecycleActionExecutor] ran action "second" on transition "close".',
				],
			],
			'conditions' => [
				[
					['action' => 'selfEq', 'condition' => "@self.status == 'closed'"],
					['action' => 'selfNe', 'condition' => "  @self.status!='closed'  "],
					['action' => 'prevEq', 'condition' => "@previous.kind == 'B'"],
					['action' => 'prevNe', 'condition' => "@previous.kind != 'B'"],
					['action' => 'missingEqEmpty', 'condition' => "@self.nothing == ''"],
					['action' => 'emptyCondition', 'condition' => ''],
					['action' => 'nonString', 'condition' => ['==' => 1]],
					['action' => 'trailEq', 'condition' => "@self.kind == 'A'"],
				],
				'{"status":"closed","kind":"A","trail":["selfEq","prevEq","missingEqEmpty","emptyCondition","nonString","trailEq"]}',
				null,
			],
			'not an object' => [
				[['action' => 'first'], 'oops'],
				'!Lifecycle transition "close" declares a malformed action (not an object).',
				null,
			],
			'no name' => [
				[['action' => ''], ['action' => 'never']],
				'!Lifecycle transition "close" declares an action without an "action" name.',
				[],
			],
			'bad condition' => [
				[['action' => 'first', 'condition' => '@self.status === "x"']],
				'!Lifecycle action condition "@self.status === "x"" is not a supported expression.',
				[],
			],
			'bad condition on a named action is refused before resolution' => [
				[['action' => 'ghost', 'condition' => 'true']],
				'!Lifecycle action condition "true" is not a supported expression.',
				[],
			],
			'missing handler' => [
				[['action' => 'first'], ['action' => 'ghost']],
				'!no handler for ghost',
				null,
			],
			'skipped handler is never resolved' => [
				[['action' => 'ghost', 'condition' => "@self.status == 'open'"]],
				'{"status":"closed","kind":"A","trail":[]}',
				[],
			],
		];
		foreach ($cases as $name => [$actions, $expected, $trace]) {
			$this->assertSame($expected, $this->outcome($actions), $name);
			if ($trace !== null) {
				$this->assertSame($trace, $this->trace, $name . ' trace');
			}
		}

		$this->outcome([['action' => 'first'], ['action' => 'ghost']]);
		$this->assertSame(['resolve first'], array_slice($this->trace, 0, 1));
		$this->assertSame('resolve ghost', end($this->trace));
	}//end testRunMatchesTheRecordedTranscripts()
}//end class
