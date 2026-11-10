<?php

/**
 * MacroActionValidator: declared flows found by name past unrelated or malformed entries, and a non-list declaration.
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\MacroActionValidator
 * @uses \OCA\OpenRegister\Service\Flow\MacroActionBinding
 */
class MacroActionValidatorDeclaredBehaviourTest extends TestCase {

	private function validator(): MacroActionValidator {
		$flows = $this->createMock(FlowMapperInterface::class);
		$flows->method('findByUuid')->willThrowException(new DoesNotExistException('none'));

		return new MacroActionValidator(flows: $flows, derivation: new FlowTriggerDerivation());
	}//end validator()

	/**
	 * @param mixed $declared The x-openregister-flows value.
	 *
	 * @return array<string, mixed>
	 */
	private function configuration(mixed $declared): array {
		return [
			'x-openregister-action' => ['suspend' => ['name' => 'Opschorten', 'macro' => true, 'flow' => 'Opschorten']],
			'x-openregister-flows' => $declared,
		];
	}//end configuration()

	public function testTheNamedFlowIsFoundPastOtherAndMalformedEntries(): void {
		$declared = [
			'not-a-flow',
			['name' => 'Something else', 'nodes' => []],
			['name' => ' Opschorten ', 'nodes' => ['not-a-node', ['id' => 'x', 'type' => 'openregister.set-fields'], ['id' => 's', 'type' => 'openregister.trigger-manual']]],
		];

		self::assertSame([], $this->validator()->refusals($this->configuration($declared)));
	}//end testTheNamedFlowIsFoundPastOtherAndMalformedEntries()

	public function testTheNamedFlowWithoutAManualTriggerIsRefusedEvenAfterAnotherThatHasOne(): void {
		$declared = [
			['name' => 'Something else', 'nodes' => [['id' => 's', 'type' => 'openregister.trigger-manual']]],
			['name' => 'Opschorten', 'nodes' => [['id' => 'x', 'type' => 'openregister.set-fields']]],
		];

		self::assertSame(
			['Action "suspend": flow "Opschorten" has no manual trigger, so nothing in it starts when the action is invoked.'],
			$this->validator()->refusals($this->configuration($declared))
		);
	}//end testTheNamedFlowWithoutAManualTriggerIsRefusedEvenAfterAnotherThatHasOne()

	public function testADeclarationThatIsNotAListDeclaresNothing(): void {
		self::assertSame(
			['Action "suspend": flow "Opschorten" does not exist.'],
			$this->validator()->refusals($this->configuration('Opschorten'))
		);
	}//end testADeclarationThatIsNotAListDeclaresNothing()
}//end class
