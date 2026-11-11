<?php

/**
 * Characterisation test for the save-time refusals of a decision-table step.
 *
 * Pins the exact message of every table-resolution and default-row refusal,
 * in the order they are checked, and the configs that are accepted, so the
 * checks can be split into smaller units without changing what an author sees.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Golden refusals for a decision-table step's config.
 */
class DecisionTableNodeRefusalsGoldenTest extends TestCase {

	/**
	 * The node under test.
	 *
	 * @var DecisionTableNode
	 */
	private DecisionTableNode $node;

	/**
	 * Build the node without a table store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		$this->node = new DecisionTableNode(
			engine: new DecisionTableEvaluator(),
			validator: new DecisionTableValidator(),
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class))
		);

	}//end setUp()

	/**
	 * A valid table with two outputs.
	 *
	 * @param string $hitPolicy The hit policy.
	 *
	 * @return array<string, mixed>
	 */
	private static function table(string $hitPolicy = 'UNIQUE'): array {
		return [
			'hitPolicy' => $hitPolicy,
			'inputs' => [['name' => 'severity', 'type' => 'string']],
			'outputs' => [['name' => 'intervention', 'type' => 'string'], ['name' => 'term', 'type' => 'string']],
			'rules' => [['inputEntries' => ['"high"'], 'outputEntries' => ['"visit"', '"1w"']]],
		];

	}//end table()

	/**
	 * The refusal message for a config, or null when it is accepted.
	 *
	 * @param array<string, mixed> $config The step config.
	 *
	 * @return string|null The message.
	 */
	private function refusal(array $config): ?string {
		try {
			$this->node->validateConfig($config);
		} catch (UnexpectedValueException $e) {
			return $e->getMessage();
		}

		return null;

	}//end refusal()

	/**
	 * Every table-resolution refusal, and an accepted inline table.
	 *
	 * @return void
	 */
	public function testTableResolution(): void {
		$this->assertSame(
			'A decision-table step takes an inline table or a stored table, not both.',
			$this->refusal(['table' => self::table(), 'tableRef' => 'lhs'])
		);
		$this->assertSame('A decision-table step needs a table.', $this->refusal([]));
		$this->assertSame('A decision-table step needs a table.', $this->refusal(['table' => [], 'tableRef' => '  ']));
		$this->assertSame('A decision-table step needs a table.', $this->refusal(['table' => 'not-a-table']));
		$this->assertStringStartsWith('The decision table cannot be run: ', (string)$this->refusal(['table' => self::table('BOGUS')]));
		$this->assertNull($this->refusal(['table' => self::table()]));

	}//end testTableResolution()

	/**
	 * Every default-row refusal, in check order, and the accepted defaults.
	 *
	 * @return void
	 */
	public function testDefaultRows(): void {
		$this->assertSame(
			'The defaultOutputs must be an object of output name to value.',
			$this->refusal(['table' => self::table(), 'defaultOutputs' => 'none'])
		);
		$this->assertSame(
			'The defaultOutputs must be an object of output name to value.',
			$this->refusal(['table' => self::table('COLLECT'), 'defaultOutputs' => null])
		);
		$this->assertSame(
			'A COLLECT table never fails to match: its empty list is the answer. Remove defaultOutputs.',
			$this->refusal(['table' => self::table(' collect '), 'defaultOutputs' => []])
		);
		$this->assertSame(
			'The defaultOutputs are missing a value for output "term". A partial default writes half a decision.',
			$this->refusal(['table' => self::table(), 'defaultOutputs' => ['intervention' => 'none', 'extra' => 1]])
		);
		$this->assertSame(
			'The defaultOutputs name "extra", which the table does not declare. The step would silently ignore it.',
			$this->refusal(['table' => self::table(), 'defaultOutputs' => ['intervention' => 'none', 'term' => null, 'extra' => 1]])
		);
		$this->assertNull($this->refusal(['table' => self::table(), 'defaultOutputs' => ['term' => '', 'intervention' => 'none']]));

	}//end testDefaultRows()
}//end class
