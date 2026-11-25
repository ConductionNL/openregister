<?php

/**
 * CodeNode: the config it refuses, the runner it needs and the items it returns.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\CodeRun;
use OCA\OpenRegister\Service\Flow\CodeRunAnswer;
use OCA\OpenRegister\Service\Flow\CodeRunFailedException;
use OCA\OpenRegister\Service\Flow\CodeRunnerClientInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\CodeNode;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\CodeNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 * @uses \OCA\OpenRegister\Service\Flow\CodeRun
 * @uses \OCA\OpenRegister\Service\Flow\CodeRunAnswer
 * @uses \OCA\OpenRegister\Service\Flow\CodeRunFailedException
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemOptions
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeCall
 */
final class CodeNodeTest extends TestCase {

	/**
	 * The runner.
	 *
	 * @var CodeRunnerClientInterface&MockObject
	 */
	private CodeRunnerClientInterface $runner;

	/**
	 * The instance ceilings as stored, by key.
	 *
	 * @var array<string, int>
	 */
	private array $ceilings = [];

	/**
	 * The node under test.
	 *
	 * @var CodeNode
	 */
	private CodeNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params=[]): string {
				foreach ($params as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}

				return $text;
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/' . $app . '/img/' . $image);
		$this->runner = $this->createMock(CodeRunnerClientInterface::class);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default): int => ($this->ceilings[$key] ?? $default)
		);
		$this->node = new CodeNode($l10n, $urls, $this->runner, $config);
	}

	/**
	 * A config that passes.
	 *
	 * @param array $overrides Keys to replace.
	 *
	 * @return array
	 */
	private static function config(array $overrides=[]): array {
		return array_merge(['language' => 'javascript', 'source' => 'item.json.naam = item.json.naam.toUpperCase(); return item;', 'mode' => 'perItem'], $overrides);
	}

	public function testItIsTheCodeStepInTheLogicPalette(): void {
		self::assertSame('openregister.code', $this->node->getId());
		self::assertSame('Code', $this->node->getDisplayName());
		self::assertSame('/core/img/filetypes/text-code.svg', $this->node->getIcon());
		self::assertSame(IFlowNodeTaxonomy::KIND_SCRIPT_TASK, $this->node->getKind());
		self::assertSame(IFlowNodeTaxonomy::CATEGORY_LOGIC, $this->node->getCategory());
		self::assertSame(['language', 'source', 'mode', 'timeoutMs', 'memoryMb', 'egress'], $this->node->configKeys());
		self::assertNotSame('', $this->node->getDescription());
	}

	public function testItIsOfferedOnlyWhileTheRunnerAnswers(): void {
		$this->runner->method('isAvailable')->willReturnOnConsecutiveCalls(true, false);

		self::assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		self::assertFalse($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
	}

	public function testAnUnknownScopeIsRefusedWithoutAskingTheRunner(): void {
		$this->runner->expects($this->never())->method('isAvailable');

		self::assertFalse($this->node->isAvailableForScope(99));
	}

	public function testAValidStepPassesWhenTheRunnerAnswers(): void {
		$this->runner->method('isAvailable')->willReturn(true);

		$this->node->validateConfig(self::config(['timeoutMs' => 30000, 'memoryMb' => 256, 'egress' => ['api.example.org', 'Data.Example.NL']]));
		$this->node->validateConfig(['source' => 'return items;', 'mode' => 'allItems']);
		$this->addToAssertionCount(1);
	}

	public function testWithoutTheRunnerTheStepIsRefusedNamingIt(): void {
		$this->runner->method('isAvailable')->willReturn(false);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('A code step needs the flow-code-runner ExApp, which is not installed or not answering.');

		$this->node->validateConfig(self::config());
	}

	/**
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function refusedConfigs(): array {
		return [
			'python'             => [['language' => 'python'], 'A code step runs JavaScript; ""python"" is not supported.'],
			'no source'          => [['source' => '  '], 'A code step needs source code.'],
			'source not text'    => [['source' => ['x']], 'A code step needs source code.'],
			'unknown mode'       => [['mode' => 'perBatch'], 'mode must be perItem or allItems'],
			'timeout as text'    => [['timeoutMs' => '1000'], 'timeoutMs must be a whole number above zero.'],
			'zero memory'        => [['memoryMb' => 0], 'memoryMb must be a whole number above zero.'],
			'timeout over cap'   => [['timeoutMs' => 30001], "timeoutMs of 30001 is above this instance's ceiling of 30000."],
			'memory over cap'    => [['memoryMb' => 257], "memoryMb of 257 is above this instance's ceiling of 256."],
			'egress not a list'  => [['egress' => 'api.example.org'], 'egress must be a list of host names.'],
			'egress with scheme' => [['egress' => ['https://api.example.org']], 'is not a public host name the code may call.'],
			'egress an ip'       => [['egress' => ['10.0.0.1']], 'is not a public host name the code may call.'],
			'egress localhost'   => [['egress' => ['localhost']], 'is not a public host name the code may call.'],
			'egress local name'  => [['egress' => ['printer.local']], 'is not a public host name the code may call.'],
			'egress with port'   => [['egress' => ['api.example.org:8080']], 'is not a public host name the code may call.'],
		];
	}

	/**
	 * @dataProvider refusedConfigs
	 */
	public function testAConfigThatCannotRunIsRefused(array $overrides, string $message): void {
		$this->runner->method('isAvailable')->willReturn(true);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($message);

		$this->node->validateConfig(self::config($overrides));
	}

	public function testTheInstanceCeilingIsTheAdministratorsWhenSet(): void {
		$this->runner->method('isAvailable')->willReturn(true);
		$this->ceilings[CodeNode::CEILING_TIMEOUT_KEY] = 2000;

		$this->expectExceptionMessage("timeoutMs of 5000 is above this instance's ceiling of 2000.");

		$this->node->validateConfig(self::config());
	}

	public function testItSendsTheJsonOfEachItemWithTheLimitsAndPairsTheAnswers(): void {
		$sent = null;
		$this->runner->expects($this->once())->method('run')->willReturnCallback(
			static function (CodeRun $run) use (&$sent): CodeRunAnswer {
				$sent = $run;
				return new CodeRunAnswer(items: [['json' => ['naam' => 'A']], ['naam' => 'B']], logs: ['ok']);
			}
		);
		$items = [FlowItems::item(json: ['naam' => 'a']), FlowItems::item(json: ['naam' => 'b'])];

		$out = $this->node->execute(new FlowNodeCall(items: $items, config: self::config(['timeoutMs' => 1000, 'egress' => ['API.example.org']])));

		self::assertSame([['json' => ['naam' => 'a']], ['json' => ['naam' => 'b']]], $sent->items);
		self::assertSame('perItem', $sent->mode);
		self::assertSame(1000, $sent->timeoutMs);
		self::assertSame(CodeNode::DEFAULT_MEMORY_MB, $sent->memoryMb);
		self::assertSame(['api.example.org'], $sent->egress);
		self::assertSame(['naam' => 'A'], $out[0][FlowItems::JSON]);
		self::assertSame(['naam' => 'B'], $out[1][FlowItems::JSON]);
		self::assertSame([FlowItems::item(json: ['naam' => 'A'], options: new \OCA\OpenRegister\Service\Flow\FlowItemOptions(fromItemIndex: 0)), FlowItems::item(json: ['naam' => 'B'], options: new \OCA\OpenRegister\Service\Flow\FlowItemOptions(fromItemIndex: 1))], $out);
	}

	public function testOnAllItemsTheAnswersAreNotPairedToInputs(): void {
		$this->runner->method('run')->willReturn(new CodeRunAnswer(items: [['json' => ['total' => 2]]]));

		$out = $this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: ['n' => 1]), FlowItems::item(json: ['n' => 1])], config: self::config(['mode' => 'allItems'])));

		self::assertSame([FlowItems::item(json: ['total' => 2])], $out);
	}

	public function testAFailingCallIsThrownSoTheEdgeDecides(): void {
		$this->runner->method('run')->willThrowException(CodeRunFailedException::withLogs(message: 'The code step failed: Script execution timed out.'));

		$this->expectException(CodeRunFailedException::class);
		$this->expectExceptionMessage('timed out');

		$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: ['n' => 1])], config: self::config(['timeoutMs' => 1000])));
	}
}
