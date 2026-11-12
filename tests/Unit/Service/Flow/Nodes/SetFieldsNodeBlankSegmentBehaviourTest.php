<?php

/**
 * SetFieldsNode: a templated path segment that renders to whitespace is refused, not written under an empty key.
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

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueRenderer
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\SetFieldsPlan
 * @uses \OCA\OpenRegister\Service\Flow\FlowFieldPath
 */
class SetFieldsNodeBlankSegmentBehaviourTest extends TestCase {

	private function node(): SetFieldsNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new SetFieldsNode($l10n, $this->createMock(IURLGenerator::class));
	}//end node()

	public function testAWhitespaceSegmentIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The path segment "{{stage}}" resolved to nothing; a field position is never empty.');

		$this->node()->execute(new FlowNodeCall(items: [FlowItems::item(json: ['stage' => '   '])], config: ['set' => ['stages.{{stage}}.status' => 'done']], context: []));
	}//end testAWhitespaceSegmentIsRefused()

	public function testAPaddedSegmentNamesTheTrimmedField(): void {
		$out = $this->node()->execute(new FlowNodeCall(items: [FlowItems::item(json: ['stage' => ' build '])], config: ['set' => ['stages.{{stage}}.status' => 'done']], context: []));

		self::assertSame(['build' => ['status' => 'done']], $out[0][FlowItems::JSON]['stages']);
	}//end testAPaddedSegmentNamesTheTrimmedField()
}//end class
