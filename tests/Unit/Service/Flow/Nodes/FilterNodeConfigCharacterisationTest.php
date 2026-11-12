<?php

/**
 * Characterisation of FilterNode::validateConfig(): what is accepted and
 * the exact refusal (text and parameters) for everything else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\FilterNode
 */
final class FilterNodeConfigCharacterisationTest extends TestCase {

	/**
	 * Conditions and today's verdict: 'ok', or the refusal as JSON of the
	 * untranslated text and its parameters.
	 *
	 * @return array<string, array{0: array, 1: string}> The config and the verdict.
	 */
	public static function configs(): array {
		$constant = 'A filter condition must be an expression, not a fixed value: "{given}" answers the same for every item.';
		$template = 'A filter condition is an expression, not a template. Write {example} rather than "{given}".';
		$example  = '{"==": [{"var": "json.status"}, "synced"]}';

		return [
			'absent' => [[], json_encode(['A filter needs a condition.', []])],
			'null' => [['condition' => null], json_encode(['A filter needs a condition.', []])],
			'empty array' => [['condition' => []], json_encode(['A filter needs a condition.', []])],
			'template string' => [
				['condition' => '{{ status == "synced" }}'],
				json_encode([$template, ['example' => $example, 'given' => '{{ status == "synced" }}']]),
			],
			'plain string' => [['condition' => 'synced'], json_encode([$constant, ['given' => "'synced'"]])],
			'empty string' => [['condition' => ''], json_encode([$constant, ['given' => "''"]])],
			'true' => [['condition' => true], json_encode([$constant, ['given' => 'true']])],
			'false' => [['condition' => false], json_encode([$constant, ['given' => 'false']])],
			'number' => [['condition' => 0], json_encode([$constant, ['given' => '0']])],
			'unknown operator' => [
				['condition' => ['nonsense-op' => [1, 2]]],
				json_encode(['That condition is not a valid expression.', []]),
			],
			'expression' => [['condition' => ['==' => [['var' => 'json.status'], 'synced']]], 'ok'],
		];
	}//end configs()

	/**
	 * Each condition gets exactly today's verdict.
	 *
	 * @param array  $config   The step configuration.
	 * @param string $expected The verdict.
	 *
	 * @return void
	 */
	#[DataProvider('configs')]
	public function testTheVerdictIsUnchanged(array $config, string $expected): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => (string)json_encode([$text, $parameters])
		);

		$node = new FilterNode($l10n, $this->createMock(IURLGenerator::class));

		try {
			$node->validateConfig($config);
			$actual = 'ok';
		} catch (UnexpectedValueException $refused) {
			$actual = $refused->getMessage();
		}

		$this->assertSame($expected, $actual);
	}//end testTheVerdictIsUnchanged()
}//end class
