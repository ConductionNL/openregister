<?php

/**
 * The code step's step report, its right and its runtime as the preflight and the
 * rights guard see them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\CodeRun;
use OCA\OpenRegister\Service\Flow\CodeRunAnswer;
use OCA\OpenRegister\Service\Flow\CodeRunFailedException;
use OCA\OpenRegister\Service\Flow\CodeRunnerClientInterface;
use OCA\OpenRegister\Service\Flow\FlowAccessInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRightRefused;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\CodeNode;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\CodeNode
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard
 */
final class CodeNodeRunnerAndRightTest extends TestCase {

	private const SOURCE = 'item.json.naam = item.json.naam.toUpperCase(); return item;';

	private CodeRunnerClientInterface&MockObject $runner;

	private CodeNode $node;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				foreach ($params as $key => $value) {
					$text = str_replace('{' . $key . '}', (string) $value, $text);
				}

				return $text;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);
		$this->runner = $this->createMock(CodeRunnerClientInterface::class);
		$this->node = new CodeNode($l10n, $this->createMock(IURLGenerator::class), $this->runner, $config);
	}

	/**
	 * A two-node flow whose first node is a code step.
	 *
	 * @return array<string, mixed>
	 */
	private static function codeFlow(): array {
		return [
			'name' => 'naam in hoofdletters',
			'nodes' => [['id' => 'a', 'type' => 'openregister.code', 'config' => ['language' => 'javascript', 'source' => self::SOURCE, 'mode' => 'perItem']], ['id' => 'b']],
			'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
		];
	}

	private function preflight(): FlowNodePreflight {
		$registry = $this->createMock(FlowNodeCatalogueInterface::class);
		$registry->method('get')->willReturnCallback(
			function (string $type): IFlowNode {
				if ($type !== 'openregister.code') {
					throw new UnexpectedValueException('No app provides "' . $type . '"');
				}

				return $this->node;
			}
		);

		return new FlowNodePreflight($registry, $this->createMock(IAppManager::class), $this->createMock(LoggerInterface::class));
	}

	/**
	 * A call with a step report in its context, as the engine hands one.
	 *
	 * @param FlowStepReport $report The report.
	 */
	private static function call(FlowStepReport $report): FlowNodeCall {
		return new FlowNodeCall(
			items: [FlowItems::item(json: ['naam' => 'jan']), FlowItems::item(json: ['naam' => 'piet'])],
			config: ['language' => 'javascript', 'source' => self::SOURCE, 'mode' => 'perItem'],
			context: [FlowStepReport::CONTEXT_KEY => $report]
		);
	}

	public function testTheStepReportRecordsTheSourceItsHashAndTheRunnersLogs(): void {
		$this->runner->method('run')->willReturn(
			new CodeRunAnswer(items: [['json' => ['naam' => 'JAN']], ['json' => ['naam' => 'PIET']]], logs: ['started', 'done'], durationMs: 12)
		);
		$report = new FlowStepReport();

		$this->node->execute(self::call(report: $report));
		$detail = $report->take();

		$this->assertSame(
			['language' => 'javascript', 'mode' => 'perItem', 'sourceHash' => hash('sha256', self::SOURCE), 'source' => self::SOURCE],
			$detail['code']
		);
		$this->assertSame(['durationMs' => 12, 'itemsIn' => 2, 'itemsOut' => 2], $detail['runner']);
		$this->assertSame(['started', 'done'], array_column($detail['lines'], 'message'));
	}

	public function testAFailedRunKeepsTheSourceTheLogsAndTheFailureOnTheReport(): void {
		$this->runner->method('run')->willThrowException(CodeRunFailedException::withLogs(message: 'The code step failed: timeout', logs: ['looping']));
		$report = new FlowStepReport();

		try {
			$this->node->execute(self::call(report: $report));
			$this->fail('A failed run must be thrown so the edge decides');
		} catch (CodeRunFailedException $e) {
			$this->assertSame('The code step failed: timeout', $e->getMessage());
		}

		$detail = $report->take();
		$this->assertSame(hash('sha256', self::SOURCE), $detail['code']['sourceHash']);
		$this->assertSame([['info', 'looping'], ['error', 'The code step failed: timeout']], array_map(static fn (array $l): array => [$l['level'], $l['message']], $detail['lines']));
		$this->assertArrayNotHasKey('runner', $detail);
	}

	public function testWithoutAStepReportTheStepStillRuns(): void {
		$this->runner->method('run')->willReturn(new CodeRunAnswer(items: [['json' => ['naam' => 'JAN']]], logs: ['x']));

		$out = $this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: ['naam' => 'jan'])], config: ['source' => self::SOURCE]));

		$this->assertCount(1, $out);
	}

	public function testTheRuntimeReasonNamesTheRunnerOnlyWhileItIsAbsent(): void {
		$this->runner->method('isAvailable')->willReturnOnConsecutiveCalls(false, true);

		$this->assertStringContainsString('flow-code-runner', (string) $this->node->unavailableReason());
		$this->assertNull($this->node->unavailableReason());
	}

	public function testThePreflightRefusesAFlowWithACodeStepWithoutTheRunnerNamingIt(): void {
		$this->runner->method('isAvailable')->willReturn(false);
		$preflight = $this->preflight();

		$report = $preflight->inspect(flow: self::codeFlow());

		$this->assertSame(FlowNodePreflight::REASON_RUNTIME_UNAVAILABLE, $report['blocking'][0]['reason']);
		$this->assertSame('a', $report['blocking'][0]['step']);
		$this->assertCount(1, array_filter($report['blocking'], static fn (array $f): bool => $f['step'] === 'a'), 'One finding: the runtime, not also a config rejection');

		try {
			$preflight->assertRunnable(flow: self::codeFlow());
			$this->fail('A flow with a code step must not save without the runner');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('flow-code-runner', $e->getMessage());
			$this->assertStringContainsString('cannot run on this instance', $e->getMessage());
		}
	}

	public function testThePreflightPassesTheCodeStepWhileTheRunnerAnswers(): void {
		$this->runner->method('isAvailable')->willReturn(true);

		$blocking = $this->preflight()->inspect(flow: self::codeFlow())['blocking'];

		$this->assertSame([], array_values(array_filter($blocking, static fn (array $f): bool => $f['step'] === 'a')));
	}

	public function testACodeStepNeedsTheFlowCodeRightWhateverItsConfig(): void {
		$this->assertSame('flow.code', $this->node->requiredRight(config: []));
		$this->assertSame('flow.code', $this->node->requiredRight(config: ['mode' => 'allItems']));
		$this->assertSame(['flow.code'], array_keys($this->node->declaredRights()));
	}

	/**
	 * @dataProvider authors
	 */
	public function testTheGuardRefusesAnAuthorWithoutFlowCode(bool $holds): void {
		$registry = $this->createMock(FlowNodeRegistryInterface::class);
		$registry->method('get')->willReturn($this->node);
		$access = $this->createMock(FlowAccessInterface::class);
		$access->expects($this->once())->method('may')->with($this->anything(), 'flow.code')->willReturn($holds);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{}');
		$guard = new FlowNodeRightsGuard(nodes: $registry, access: $access, appConfig: $config);

		if ($holds === false) {
			$this->expectException(FlowNodeRightRefused::class);
			$this->expectExceptionMessageMatches('/flow\.code/');
		}

		$guard->assertMayAuthor(user: $this->createMock(IUser::class), document: self::codeFlow());
		$this->addToAssertionCount(1);
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function authors(): array {
		return ['without flow.code' => [false], 'with flow.code' => [true]];
	}

	public function testTheRightIsSeededToAdministratorsOnly(): void {
		$seed = json_decode((string) file_get_contents(__DIR__ . '/../../../../../lib/actions.seed.json'), true);

		$this->assertSame(['admin'], $seed['actions']['flow.code'] ?? null);
	}
}
