<?php

/**
 * Unit tests for the macro-action binding and its validator.
 *
 * All three flow refusals are SILENT at run time: an action bound to a missing,
 * unpublished or trigger-less flow appears in the menu, does nothing when
 * clicked, and looks exactly like a flow that ran and changed nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use OCA\OpenRegister\Service\Flow\MacroActionBinding;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MacroActionValidatorTest extends TestCase {

	private FlowMapper&MockObject $flows;

	private MacroActionValidator $validator;

	protected function setUp(): void {
		parent::setUp();

		$this->flows = $this->createMock(FlowMapper::class);
		$this->validator = new MacroActionValidator($this->flows, new FlowTriggerDerivation());
	}//end setUp()

	/**
	 * A schema configuration declaring one macro action.
	 *
	 * @param array $extra Extra keys on the declaration.
	 *
	 * @return array The configuration.
	 */
	private function configuration(array $extra = ['macro' => true, 'flow' => 'flow-1']): array {
		return [
			'x-openregister-action' => [
				'close-and-notify' => array_merge(
					['name' => 'Close and notify', 'description' => 'Close the case and tell the applicant.'],
					$extra
				),
			],
		];
	}//end configuration()

	/**
	 * A real Flow: Entity getters are magic and a mock cannot answer them.
	 *
	 * @param string $status The lifecycle status.
	 * @param array  $nodes  The nodes.
	 *
	 * @return Flow
	 */
	private function flow(string $status, array $nodes): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setName('Close and notify');
		$flow->setLifecycleStatus($status);
		$flow->setNodes($nodes);
		return $flow;
	}//end flow()

	/**
	 * A published flow with a manual trigger is runnable, so nothing is
	 * refused. Paired with the refusals below on purpose: a validator that
	 * refused everything would pass each of them on its own.
	 *
	 * @return void
	 */
	public function testAPublishedFlowWithAManualTriggerIsAccepted(): void {
		$this->flows->method('findByUuid')->willReturn(
			$this->flow(
				FlowVersion::STATUS_PUBLISHED,
				[['type' => FlowNextHint::MANUAL_TRIGGER, 'config' => []]]
			)
		);

		$this->assertSame([], $this->validator->refusals($this->configuration()));
	}//end testAPublishedFlowWithAManualTriggerIsAccepted()

	/**
	 * Refusal one: the flow does not exist.
	 *
	 * @return void
	 */
	public function testAMissingFlowIsRefusedByName(): void {
		$this->flows->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$refusals = $this->validator->refusals($this->configuration());

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('does not exist', $refusals[0]);
		$this->assertStringContainsString('close-and-notify', $refusals[0]);
	}//end testAMissingFlowIsRefusedByName()

	/**
	 * Refusal two: the flow is not published.
	 *
	 * @return void
	 */
	public function testAnUnpublishedFlowIsRefused(): void {
		$this->flows->method('findByUuid')->willReturn(
			$this->flow(
				FlowVersion::STATUS_DRAFT,
				[['type' => FlowNextHint::MANUAL_TRIGGER, 'config' => []]]
			)
		);

		$refusals = $this->validator->refusals($this->configuration());

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('not published', $refusals[0]);
	}//end testAnUnpublishedFlowIsRefused()

	/**
	 * Refusal three: nothing in the flow starts when the action is invoked.
	 *
	 * @return void
	 */
	public function testAFlowWithoutAManualTriggerIsRefused(): void {
		$this->flows->method('findByUuid')->willReturn(
			$this->flow(
				FlowVersion::STATUS_PUBLISHED,
				[['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 9 * * *']]]
			)
		);

		$refusals = $this->validator->refusals($this->configuration());

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('no manual trigger', $refusals[0]);
	}//end testAFlowWithoutAManualTriggerIsRefused()

	/**
	 * A macro with nothing to run is refused without a lookup: it would save,
	 * appear in the menu and do nothing.
	 *
	 * @return void
	 */
	public function testAMacroWithNoFlowIsRefusedWithoutALookup(): void {
		$this->flows->expects($this->never())->method('findByUuid');

		$refusals = $this->validator->refusals($this->configuration(['macro' => true]));

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('no "flow" is named', $refusals[0]);
	}//end testAMacroWithNoFlowIsRefusedWithoutALookup()

	/**
	 * The mirror: a flow nothing will ever run. Saved quietly it reads as a
	 * bound macro to anyone looking at the schema afterwards.
	 *
	 * @return void
	 */
	public function testAFlowWithoutMacroTrueIsRefused(): void {
		$refusals = $this->validator->refusals($this->configuration(['flow' => 'flow-1']));

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('"macro" is not true', $refusals[0]);
	}//end testAFlowWithoutMacroTrueIsRefused()

	/**
	 * A declared action that says nothing about macros is left alone. Most
	 * declared actions are not macros, and refusing them would break every
	 * schema that already declares one.
	 *
	 * @return void
	 */
	public function testAnOrdinaryDeclaredActionIsUntouched(): void {
		$this->flows->expects($this->never())->method('findByUuid');

		$configuration = [
			'x-openregister-action' => [
				'sendMail' => ['name' => 'Send mail', 'description' => 'Send a message as the acting user.'],
			],
		];

		$this->assertSame([], $this->validator->refusals($configuration));
		$this->assertSame([], MacroActionBinding::parse($configuration));
	}//end testAnOrdinaryDeclaredActionIsUntouched()

	/**
	 * A malformed binding is not returned as a binding. Returned, a caller
	 * could act on one the save is about to reject.
	 *
	 * @return void
	 */
	public function testAMalformedBindingIsNotParsedAsOne(): void {
		$this->assertSame([], MacroActionBinding::parse($this->configuration(['macro' => true, 'flow' => '  '])));
		$this->assertNotSame([], MacroActionBinding::refusals($this->configuration(['macro' => true, 'flow' => '  '])));
	}//end testAMalformedBindingIsNotParsedAsOne()
}//end class
