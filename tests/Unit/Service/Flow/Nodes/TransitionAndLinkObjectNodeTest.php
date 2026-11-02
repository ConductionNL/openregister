<?php

/**
 * Unit tests for the two built-in nodes object actions need: transition and link-object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNode;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

class TransitionAndLinkObjectNodeTest extends TestCase {

	private TransitionEngine&MockObject $engine;

	private ObjectService&MockObject $objects;

	private HandoffService&MockObject $handoffs;

	private IL10N&MockObject $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->engine = $this->createMock(TransitionEngine::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->handoffs = $this->createMock(HandoffService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
	}//end setUp()

	/**
	 * The transition node.
	 *
	 * @return TransitionNode
	 */
	private function transition(): TransitionNode {
		return new TransitionNode($this->engine, $this->l10n, $this->createMock(IURLGenerator::class));
	}//end transition()

	/**
	 * The link-object node.
	 *
	 * @return LinkObjectNode
	 */
	private function link(): LinkObjectNode {
		return new LinkObjectNode($this->objects, $this->handoffs, $this->l10n, $this->createMock(IURLGenerator::class));
	}//end link()

	/**
	 * An item about a request object.
	 *
	 * @return array
	 */
	private function item(): array {
		return FlowItems::item(json: ['uuid' => 'req-1', 'title' => 'Aanvraag', 'reason' => 'complete', '@self' => ['register' => '3', 'schema' => '7']]);
	}//end item()

	/**
	 * An object.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setObject($data);
		return $object;
	}//end object()

	public function testATransitionMovesTheItemsObjectWithRenderedData(): void {
		$this->engine->expects($this->once())->method('transition')
			->with('req-1', 'fulfil', ['grond' => 'complete'])
			->willReturn($this->object('req-1', ['status' => 'fulfilled']));

		$out = $this->transition()->execute([$this->item()], ['action' => 'fulfil', 'data' => ['grond' => '{{reason}}']], []);

		$this->assertCount(1, $out);
	}//end testATransitionMovesTheItemsObjectWithRenderedData()

	public function testARefusedTransitionFailsTheStepWithTheEnginesMessage(): void {
		$this->engine->method('transition')->willThrowException(new RuntimeException('Guard "signed" refused the move.'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Guard "signed" refused the move.');

		$this->transition()->execute([$this->item()], ['action' => 'fulfil'], []);
	}//end testARefusedTransitionFailsTheStepWithTheEnginesMessage()

	public function testATransitionStepMustNameItsAction(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->transition()->validateConfig([]);
	}//end testATransitionStepMustNameItsAction()

	public function testATransitionMayMoveAnotherObject(): void {
		$this->engine->expects($this->once())->method('transition')->with('case-9', 'open', [])->willReturn($this->object('case-9'));

		$this->transition()->execute([FlowItems::item(json: ['uuid' => 'req-1', 'case' => 'case-9'])], ['action' => 'open', 'object' => '{{case}}'], []);
	}//end testATransitionMayMoveAnotherObject()

	public function testAHandOverWithoutItsTargetAppFailsVisibly(): void {
		$this->handoffs->method('execute')->with('3', '7', 'req-1', 'convert-to-case')
			->willThrowException(new HandoffException(errorCode: HandoffException::PROVIDER_UNAVAILABLE, message: 'No app implements Case.'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not-available: No app implements Case.');

		$this->link()->execute([$this->item()], ['handoff' => 'convert-to-case'], []);
	}//end testAHandOverWithoutItsTargetAppFailsVisibly()

	public function testAHandOverCarriesTheHandoffsAnswer(): void {
		$this->handoffs->method('execute')->willReturn(['status' => 'executed', 'target' => ['uuid' => 'case-1']]);

		$out = $this->link()->execute([$this->item()], ['handoff' => 'convert-to-case'], []);

		$this->assertSame('case-1', $out[0][FlowItems::JSON]['linked']['target']['uuid']);
	}//end testAHandOverCarriesTheHandoffsAnswer()

	public function testACreatedObjectIsLinkedIntoTheRelation(): void {
		$this->objects->method('find')->with(id: 'req-1')->willReturn($this->object('req-1', ['title' => 'Aanvraag', 'notes' => ['n-0']]));
		$saved = [];
		$this->objects->method('saveObject')->willReturnCallback(
			function (...$args) use (&$saved): ObjectEntity {
				$saved[] = $args;
				return $this->object('n-1', ['text' => 'x']);
			}
		);

		$this->link()->execute([$this->item()], ['register' => 'notes', 'schema' => 'note', 'data' => ['text' => '{{title}}'], 'relation' => 'notes'], []);

		// saveObject(object, extend, register, schema, uuid): the mock sees them by position.
		$this->assertSame(['text' => 'Aanvraag'], $saved[0][0]);
		$this->assertSame('note', $saved[0][3]);
		$this->assertSame(['n-0', 'n-1'], $saved[1][0]['notes']);
		$this->assertSame('req-1', $saved[1][4]);
	}//end testACreatedObjectIsLinkedIntoTheRelation()

	public function testALinkStepNamesExactlyOneMode(): void {
		$node = $this->link();

		foreach ([[], ['handoff' => 'x', 'register' => 'r', 'schema' => 's']] as $config) {
			try {
				$node->validateConfig($config);
				$this->fail('Accepted ' . json_encode($config));
			} catch (UnexpectedValueException) {
				$this->addToAssertionCount(1);
			}
		}

		$node->validateConfig(['handoff' => 'x']);
		$node->validateConfig(['register' => 'r', 'schema' => 's']);
	}//end testALinkStepNamesExactlyOneMode()
}//end class
