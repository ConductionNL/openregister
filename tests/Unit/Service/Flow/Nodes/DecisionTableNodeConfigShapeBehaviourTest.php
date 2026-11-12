<?php

/**
 * DecisionTableNode: save-time refusals of a mapping that is not an object and of a blank result path, and table columns that are not objects.
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
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableColumns
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableValidator
 * @uses \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\UnaryTestValue
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 */
class DecisionTableNodeConfigShapeBehaviourTest extends TestCase {

	private DecisionTableNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$this->node = new DecisionTableNode(
			engine: new DecisionTableEvaluator(),
			validator: new DecisionTableValidator(),
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class))
		);
	}//end setUp()

	/**
	 * @return array<string, mixed>
	 */
	private function table(): array {
		return [
			'name' => 'Fee',
			'key' => 'fee-1',
			'hitPolicy' => 'UNIQUE',
			'inputs' => [['name' => 'kind', 'type' => 'string']],
			'outputs' => [['name' => 'fee', 'type' => 'number']],
			'rules' => [['id' => 'r1', 'inputEntries' => ['"a"'], 'outputEntries' => ['10']]],
		];
	}//end table()

	public function testAMappingThatIsNotAnObjectIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The outputMapping must be an object of name to field path.');

		$this->node->validateConfig(['table' => $this->table(), 'outputMapping' => 'fee']);
	}//end testAMappingThatIsNotAnObjectIsRefused()

	public function testABlankResultKeyIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The field path for resultKey is empty. A position is never empty.');

		$this->node->validateConfig(['table' => $this->table(), 'resultKey' => '  ']);
	}//end testABlankResultKeyIsRefused()

	public function testANamedResultKeyAndAnObjectMappingAreAccepted(): void {
		$this->node->validateConfig(['table' => $this->table(), 'resultKey' => 'decision', 'inputMapping' => ['kind' => 'type']]);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The inputMapping must be an object of name to field path.');
		$this->node->validateConfig(['table' => $this->table(), 'resultKey' => 'decision', 'inputMapping' => 7]);
	}//end testANamedResultKeyAndAnObjectMappingAreAccepted()
}//end class
