<?php

/**
 * PortalTaskConfig: the refusals at save time, the upload limits, and how a
 * template that renders to nothing or to a structure is read.
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
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskRound;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskRound
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\TaskConfigCommon
 * @uses \OCA\OpenRegister\Service\Flow\FlowAdvanceBudget
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueRenderer
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 */
class PortalTaskConfigBehaviourTest extends TestCase {

	private PortalTaskConfig $config;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$this->config = new PortalTaskConfig(l10n: $l10n);
	}//end setUp()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'blank title' => [['title' => ' '], 'Say what is being asked'],
			'blank party role' => [['partyRole' => '  '], 'Name the party role on the case'],
			'no files allowed' => [['uploadMaxFiles' => 0], 'uploadMaxFiles must be at least 1'],
			'non-numeric size' => [['uploadMaxSizeMb' => 'big'], 'uploadMaxSizeMb must be a number.'],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testValidationRefuses(array $overrides, string $message): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($message);

		$this->config->validate(config: array_merge(['title' => 'Send it'], $overrides));
	}//end testValidationRefuses()

	public function testValidationAcceptsAnOmittedRoleAndEmptyLimits(): void {
		$this->config->validate(config: ['title' => 'Send it', 'uploadMaxFiles' => '', 'uploadMaxSizeMb' => null, 'heartbeatMinutes' => '30']);

		self::assertSame('initiator', $this->config->partyRole(config: ['partyRole' => ' ']));
		self::assertSame('applicant', $this->config->partyRole(config: ['partyRole' => ' applicant ']));
	}//end testValidationAcceptsAnOmittedRoleAndEmptyLimits()

	public function testUploadLimitsAreReadAsNumbersAndBooleans(): void {
		self::assertSame(
			['required' => true, 'maxFiles' => 3, 'acceptedTypes' => ['application/pdf'], 'maxSizeBytes' => 1572864],
			$this->config->uploadConstraints(config: ['uploadRequired' => true, 'uploadMaxFiles' => '3', 'uploadAcceptedTypes' => 'application/pdf', 'uploadMaxSizeMb' => 1.5])
		);
		self::assertSame(
			['required' => false, 'maxFiles' => 1, 'acceptedTypes' => [], 'maxSizeBytes' => null],
			$this->config->uploadConstraints(config: ['uploadRequired' => false, 'uploadMaxSizeMb' => 0])
		);
		self::assertTrue($this->config->uploadConstraints(config: ['uploadRequired' => ' Yes '])['required']);
		self::assertFalse($this->config->uploadConstraints(config: ['uploadRequired' => 'no'])['required']);
	}//end testUploadLimitsAreReadAsNumbersAndBooleans()

	public function testAReasonThatRendersToAStructureIsNoReason(): void {
		$items = [FlowItems::item(json: ['review' => ['comment' => 'Blurry', 'by' => 'x']])];

		self::assertSame('', $this->config->reasonFrom(config: ['reasonField' => 'review'], items: $items));
		self::assertSame('Blurry', $this->config->reasonFrom(config: ['reasonField' => 'review.comment'], items: $items));
	}//end testAReasonThatRendersToAStructureIsNoReason()

	public function testATemplatedDescriptionRenderingToNothingOrAStructureIsDropped(): void {
		$items = [FlowItems::item(json: ['blank' => '   ', 'nested' => ['a' => 1], 'due' => '2026-12-01', '@self' => ['uuid' => 'case-1']])];
		$round = (new PortalTaskRound(items: $items, config: [
			'title' => 'Send it',
			'description' => '{{ blank }}',
			'dueAt' => '{{ nested }}',
			'expiresAt' => '{{ due }}',
		]))->atNode(nodeId: 'ask')->withParty(partyReference: 'party:1');

		$data = $this->config->taskData(round: $round, nodeType: 'openregister.portal-task');

		self::assertNull($data['description']);
		self::assertNull($data['dueAt']);
		self::assertSame('2026-12-01', $data['expiresAt']);
	}//end testATemplatedDescriptionRenderingToNothingOrAStructureIsDropped()
	public function testTheSlotRecordsTheRoundAndClearsThePass(): void {
		$round = (new PortalTaskRound(items: [FlowItems::item(json: ['@self' => ['uuid' => 'case-1']])], config: ['title' => 'Send it', 'advance' => 'all']))
			->atNode(nodeId: 'ask')
			->withParty(partyReference: 'party:1')
			->inCycle(cycle: 2, previousTaskUuid: 't-0');

		$slot = $this->config->slotValues(round: $round, taskUuid: 't-1');

		self::assertSame('t-1', $slot['taskUuid']);
		self::assertSame('party:1', $slot['assignee']);
		self::assertSame('Send it', $slot['title']);
		self::assertSame(2, $slot[PortalTaskConfig::SLOT_CYCLE]);
		self::assertSame('t-0', $slot[PortalTaskConfig::SLOT_PREVIOUS_TASK_UUID]);
		self::assertNull($slot[PortalTaskConfig::SLOT_PASSED_AT]);
		self::assertSame('case-1', $this->config->subjectObjectUuid(items: $round->items));
		self::assertSame('', $this->config->subjectObjectUuid(items: [FlowItems::item(json: ['x' => 1])]));
	}//end testTheSlotRecordsTheRoundAndClearsThePass()

	public function testDefaultsForTheReasonFieldTheBehavioursAndTheHeartbeat(): void {
		self::assertSame('reason', $this->config->reasonField(config: ['reasonField' => ' ']));
		$round = (new PortalTaskRound(items: [FlowItems::item(json: [])], config: ['title' => 'Send it', 'onTimeout' => ' skip ', 'onReject' => 'dead_letter']))
			->atNode(nodeId: 'ask')->withParty(partyReference: 'party:1');
		$data = $this->config->taskData(round: $round, nodeType: 'openregister.portal-task');
		self::assertSame('skip', $data['onTimeout']);
		self::assertSame('dead_letter', $data['onReject']);
		self::assertNull($data['description']);
		self::assertNull($data['expiresAt']);

		$before = time();
		$heartbeat = $this->config->heartbeatAt(config: ['heartbeatMinutes' => 30])->getTimestamp();
		self::assertGreaterThanOrEqual($before + 1800, $heartbeat);
		self::assertLessThanOrEqual(time() + 1800, $heartbeat);
	}//end testDefaultsForTheReasonFieldTheBehavioursAndTheHeartbeat()
}//end class
