<?php

/**
 * Golden test for SetFieldsNode: validateConfig() and execute() over every
 * reshaping operation, templated paths and every path refusal.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode
 */
class SetFieldsNodeGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/set-fields-node-golden.json';

	/**
	 * @var SetFieldsNode
	 */
	private SetFieldsNode $node;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->node = new SetFieldsNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	private static function validateCases(): array {
		return [
			'empty' => [],
			'all empty lists' => ['set' => [], 'compute' => [], 'rename' => [], 'remove' => []],
			'keepOnlySet alone' => ['keepOnlySet' => true],
			'set only' => ['set' => ['a' => 1]],
			'rename only' => ['rename' => ['a' => 'b']],
			'remove only' => ['remove' => ['a']],
			'remove scalar' => ['remove' => 'a'],
			'set blank path' => ['set' => [' ' => 1]],
			'set empty path' => ['set' => ['' => 1]],
			'compute blank path' => ['compute' => [' ' => ['var' => 'json.a']]],
			'compute valid' => ['compute' => ['n' => ['+' => [['var' => 'json.a'], 1]]]],
			'compute invalid' => ['compute' => ['n' => ['nope-operator' => [1]]]],
			'compute invalid second' => ['compute' => ['ok' => ['var' => 'json.a'], 'bad' => ['nope-operator' => [1]]]],
			'set blank before compute invalid' => ['set' => [' ' => 1], 'compute' => ['bad' => ['nope-operator' => [1]]]],
			'compute blank and invalid' => ['compute' => [' ' => ['nope-operator' => [1]]]],
		];
	}//end validateCases()

	private static function executeCases(): array {
		$items = [
			['json' => ['a' => 1, 'b' => 'two', 'stage' => 'build', 'n' => 4, 'dotted' => 'x.y', 'blank' => ' ', 'list' => [1, 2]]],
			['json' => ['a' => 2, 'stage' => 'test'], 'binary' => ['file' => ['data' => 'abc']]],
		];

		return [
			'no items' => ['items' => [], 'config' => ['set' => ['a' => 1]], 'context' => []],
			'item without json' => ['items' => [['binary' => ['f' => 1]]], 'config' => ['set' => ['a' => 1]], 'context' => []],
			'nothing configured' => ['items' => $items, 'config' => [], 'context' => []],
			'remove' => ['items' => $items, 'config' => ['remove' => ['a', 'missing', 7]], 'context' => []],
			'remove scalar config' => ['items' => $items, 'config' => ['remove' => 'b'], 'context' => []],
			'rename' => ['items' => $items, 'config' => ['rename' => ['a' => 'alpha', 'missing' => 'x', '' => 'y', 'b' => '']], 'context' => []],
			'rename onto existing' => ['items' => $items, 'config' => ['rename' => ['a' => 'b']], 'context' => []],
			'remove then rename reuses name' => ['items' => $items, 'config' => ['remove' => ['b'], 'rename' => ['a' => 'b']], 'context' => []],
			'set constants' => ['items' => $items, 'config' => ['set' => ['x' => 1, 'y' => ['k' => 'v'], 'a' => null]], 'context' => []],
			'set templated values' => [
				'items' => $items,
				'config' => ['set' => ['copy' => '{{a}}', 'text' => 'stage={{stage}}', 'missing' => '{{nope}}', 'list' => '{{list}}']],
				'context' => [],
			],
			'set nested path' => ['items' => $items, 'config' => ['set' => ['p.q.r' => 1, 'a.b' => 2]], 'context' => []],
			'set templated path' => ['items' => $items, 'config' => ['set' => ['stages.{{stage}}.status' => 'done']], 'context' => []],
			'set reads earlier write' => ['items' => $items, 'config' => ['set' => ['stage' => 'deploy', 'at.{{stage}}' => '{{stage}}']], 'context' => []],
			'set path unresolved' => ['items' => $items, 'config' => ['set' => ['s.{{nope}}' => 1]], 'context' => []],
			'set path container' => ['items' => $items, 'config' => ['set' => ['s.{{list}}' => 1]], 'context' => []],
			'set path blank' => ['items' => $items, 'config' => ['set' => ['s.{{blank}}' => 1]], 'context' => []],
			'set path dotted' => ['items' => $items, 'config' => ['set' => ['s.{{dotted}}' => 1]], 'context' => []],
			'set path numeric' => ['items' => $items, 'config' => ['set' => ['s.{{a}}' => 1]], 'context' => []],
			'set path padded' => ['items' => $items, 'config' => ['set' => ['s.{{b}} ' => 1, 's.x{{a}}' => 2]], 'context' => []],
			'keepOnlySet' => ['items' => $items, 'config' => ['keepOnlySet' => true, 'set' => ['copy' => '{{a}}'], 'rename' => ['a' => 'z']], 'context' => []],
			'keepOnlySet truthy not true' => ['items' => $items, 'config' => ['keepOnlySet' => 1, 'set' => ['x' => 1]], 'context' => []],
			'compute' => [
				'items' => $items,
				'config' => [
					'set' => ['base' => '{{a}}'],
					'compute' => [
						'inc' => ['+' => [['var' => 'json.base'], 1]],
						'idx' => ['var' => 'itemIndex'],
						'cnt' => ['var' => 'itemCount'],
						'ctx' => ['var' => 'context.flowId'],
						'nested.{{stage}}' => ['*' => [['var' => 'json.a'], 10]],
						'chain' => ['var' => 'json.inc'],
					],
				],
				'context' => ['flowId' => 'f-1'],
			],
			'compute broken expression' => ['items' => $items, 'config' => ['compute' => ['bad' => ['nope-operator' => [1]]]], 'context' => []],
			'compute path refused' => ['items' => $items, 'config' => ['compute' => ['{{dotted}}' => 1]], 'context' => []],
			'keyed items' => [
				'items' => ['first' => ['json' => ['a' => 1]], 'second' => ['json' => ['a' => 2]]],
				'config' => ['compute' => ['idx' => ['var' => 'itemIndex']]],
				'context' => [],
			],
			'all operations' => [
				'items' => $items,
				'config' => [
					'remove' => ['n'],
					'rename' => ['b' => 'bee'],
					'set' => ['out.{{stage}}' => '{{bee}}'],
					'compute' => ['sum' => ['+' => [['var' => 'json.a'], 100]]],
				],
				'context' => [],
			],
		];
	}//end executeCases()

	private function validate(array $config): string {
		try {
			$this->node->validateConfig($config);
		} catch (Throwable $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}

		return 'ok';
	}//end validate()

	private function runCase(array $case): mixed {
		try {
			return $this->node->execute(new FlowNodeCall(items: $case['items'], config: $case['config'], context: $case['context']));
		} catch (Throwable $e) {
			// A TypeError names the calling file and line; that is a source
			// position, not behaviour.
			return ['error' => get_class($e) . ': ' . preg_replace('/, called in .*$/s', '', $e->getMessage())];
		}
	}//end runCase()

	public function testSetFieldsNodeMatchesGolden(): void {
		$actual = [];
		foreach (self::validateCases() as $name => $config) {
			$actual['validate: ' . $name] = $this->validate($config);
		}

		foreach (self::executeCases() as $name => $case) {
			$actual['execute: ' . $name] = $this->runCase($case);
		}

		$actual['configKeys'] = $this->node->configKeys();

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testSetFieldsNodeMatchesGolden()
}//end class
