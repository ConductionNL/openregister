<?php

/**
 * FlowStateNode and FlowStateSlots: forgetting a key, the slot-config refusal, a blank recorded field, a legacy slot that never expires, and release by holder across slot shapes.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\FlowStateSlots
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowStateHandle
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowStateStep
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 */
class FlowStateSlotsBehaviourTest extends TestCase {

	private FlowStateNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->node = new FlowStateNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	/**
	 * @param array<string, mixed> $json
	 * @param array<string, mixed> $config
	 *
	 * @return array<string, mixed> The single output item's json.
	 */
	private function step(FlowStateHandle $state, array $json, array $config): array {
		return $this->node->execute(new FlowNodeCall(items: [['json' => $json]], config: $config, context: [FlowStateHandle::CONTEXT_KEY => $state]))[0]['json'];
	}//end step()

	public function testForgetRemovesTheKeyAndPassesTheItemOn(): void {
		$state = new FlowStateHandle(['cursor' => 41, 'other' => 1]);

		$json = $this->step(state: $state, json: ['n' => 1], config: ['operation' => 'forget', 'key' => 'cursor']);

		self::assertSame(['n' => 1], $json);
		self::assertFalse($state->has('cursor'));
		self::assertTrue($state->has('other'));
	}//end testForgetRemovesTheKeyAndPassesTheItemOn()

	public function testASlotOperationNeedsTheSlotsKey(): void {
		$this->node->validateConfig(['operation' => 'release', 'slots' => 'workers']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('slots is required for claim and release');
		$this->node->validateConfig(['operation' => 'claim', 'slots' => ' ', 'capacity' => 2]);
	}//end testASlotOperationNeedsTheSlotsKey()

	public function testABlankRecordedFieldNameIsSkipped(): void {
		$state = new FlowStateHandle();

		$this->step(state: $state, json: ['holder' => 'issue-1', 'stage' => 'build'], config: ['operation' => 'claim', 'slots' => 'slots', 'capacity' => 1, 'record' => ['', 'stage']]);

		$slot = $state->get('slots')['1'];
		self::assertSame('build', $slot['stage']);
		self::assertArrayNotHasKey('', $slot);
	}//end testABlankRecordedFieldNameIsSkipped()

	public function testALegacySlotWithoutASinceIsNeverReclaimedByTheLease(): void {
		$state = new FlowStateHandle(['slots' => ['1' => 'issue-old']]);

		$json = $this->step(state: $state, json: ['holder' => 'issue-new'], config: ['operation' => 'claim', 'slots' => 'slots', 'capacity' => 1, 'leaseSeconds' => 60]);

		self::assertFalse($json['claimed']);
		self::assertNull($json['slot']);
		self::assertSame('issue-old', $state->get('slots')['1']['holder']);
	}//end testALegacySlotWithoutASinceIsNeverReclaimedByTheLease()

	public function testReleaseFindsTheHoldersSlotAcrossRecordFlagAndStringShapes(): void {
		$state = new FlowStateHandle(['slots' => ['1' => ['holder' => 'a'], '2' => true, '3' => 'b']]);

		$json = $this->step(state: $state, json: ['holder' => 'b'], config: ['operation' => 'release', 'slots' => 'slots']);

		self::assertTrue($json['released']);
		self::assertSame([['holder' => 'a'], true, null], array_values($state->get('slots')));

		$again = $this->step(state: $state, json: ['holder' => 'a'], config: ['operation' => 'release', 'slots' => 'slots']);
		self::assertTrue($again['released']);
		self::assertNull($state->get('slots')['1']);
	}//end testReleaseFindsTheHoldersSlotAcrossRecordFlagAndStringShapes()

	public function testReleaseWithoutAHolderOrASlotReleasesNothing(): void {
		$state = new FlowStateHandle(['slots' => ['1' => true]]);

		$json = $this->step(state: $state, json: [], config: ['operation' => 'release', 'slots' => 'slots']);

		self::assertFalse($json['released']);
		self::assertSame(['1' => true], $state->get('slots'));
	}//end testReleaseWithoutAHolderOrASlotReleasesNothing()
	public function testAnAnonymousClaimIsNotHeldByAHolderNamedOne(): void {
		$state = new FlowStateHandle(['slots' => ['1' => true]]);

		$json = $this->step(state: $state, json: ['holder' => '1'], config: ['operation' => 'release', 'slots' => 'slots']);

		self::assertFalse($json['released'], 'a flag slot has no holder, and true is not the holder "1"');
		self::assertSame(['1' => true], $state->get('slots'));
	}//end testAnAnonymousClaimIsNotHeldByAHolderNamedOne()
}//end class
