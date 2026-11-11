<?php

/**
 * Characterisation of what a decided set of state field rules answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\StateFieldLists;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Lifecycle\StateFieldRules
 */
final class StateFieldRulesShapeTest extends TestCase {

	/**
	 * The lists, the published shape and each per-property answer, hidden and refused alike.
	 *
	 * @return void
	 */
	public function testWhatTheDecidedRulesAnswer(): void {
		$rules = new StateFieldRules(
			state: 'open',
			fields: new StateFieldLists(hidden: [3 => 'secret'], readOnly: ['ref'], required: ['name']),
			messages: ['ref' => 'Ref is fixed.']
		);

		$this->assertSame('open', $rules->getState());
		$this->assertSame([3 => 'secret'], $rules->getHidden());
		$this->assertSame(['ref'], $rules->getReadOnly());
		$this->assertSame(['name'], $rules->getRequired());
		$this->assertSame(['state' => 'open', 'hidden' => ['secret'], 'readOnly' => ['ref'], 'required' => ['name']], $rules->jsonSerialize());
		$this->assertFalse($rules->isEmpty());
		$this->assertTrue($rules->hides('secret'));
		$this->assertFalse($rules->hides('ref'));
		$this->assertTrue($rules->freezes('ref'));
		$this->assertFalse($rules->freezes('secret'));
		$this->assertSame('Ref is fixed.', $rules->messageFor('ref'));
		$this->assertNull($rules->messageFor('name'));
	}//end testWhatTheDecidedRulesAnswer()

	/**
	 * No rules: nothing hidden, nothing frozen, no state.
	 *
	 * @return void
	 */
	public function testNoRulesAnswerNothing(): void {
		$none = new StateFieldRules();
		$this->assertTrue($none->isEmpty());
		$this->assertSame(['state' => null, 'hidden' => [], 'readOnly' => [], 'required' => []], $none->jsonSerialize());
		$this->assertFalse($none->hides('x'));
		$this->assertTrue((new StateFieldRules(state: 'closed'))->isEmpty());
		$this->assertFalse((new StateFieldRules(state: 'closed', fields: new StateFieldLists(required: ['name'])))->isEmpty());
	}//end testNoRulesAnswerNothing()
}//end class
