<?php

/**
 * What an empty rule list means, in each of the two layers.
 *
 * 🔴 IT MEANS DIFFERENT THINGS, AND THAT IS CORRECT RATHER THAN A BUG TO
 * HARMONISE. The two are different KINDS of declaration:
 *
 *  - a SCHEMA cascade is the last word, so an empty list is DENIED
 *    (`MagicRbacHandler::hasPermission()` returns false, with only the admin and
 *    owner bypasses surviving);
 *  - a PROPERTY block is a NARROWING on top of the object cascade, so an action
 *    it does not name has no opinion here and falls through to the object's own
 *    rules, which still have to pass.
 *
 * 🔑 THIS TEST EXISTS TO STOP THE HARMONISATION. Reading the two as an
 * inconsistency invites making the property side fail-closed, and that would not
 * tighten a leak: it would make every action a property block does not name
 * UNWRITABLE. Measured across the installed fleet on 2026-09-18 there are EIGHT
 * property-level blocks and ALL EIGHT ARE PARTIAL — not one names all four
 * actions — so the change would break every one of them, in decidiq and stackiq.
 *
 * A guard that can only be satisfied by breaking what it guards is worse than no
 * guard, which is the same lesson the filinq cascade test taught.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `PropertyRbacHandler` and the empty rule list.
 *
 * @covers \OCA\OpenRegister\Service\PropertyRbacHandler
 */
class AnEmptyRuleListMeansOneThingTest extends TestCase {

	/**
	 * A handler whose caller is an ordinary user in no special group.
	 *
	 * @return PropertyRbacHandler The handler.
	 */
	private function handler(): PropertyRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('a.jansen');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['medewerkers']);

		return new PropertyRbacHandler(
			$session,
			$groups,
			$this->createMock(ConditionMatcher::class),
			new NullLogger(),
			$this->createMock(StateFieldRuleResolver::class)
		);
	}//end handler()

	/**
	 * A schema whose `salaris` carries the given property block.
	 *
	 * @param array<string, mixed> $authorization The property's block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authorization): Schema {
		$schema = new Schema();
		$schema->setProperties(['salaris' => ['type' => 'number', 'authorization' => $authorization]]);

		return $schema;
	}//end schemaWith()

	/**
	 * A named action still narrows, and refuses somebody outside it.
	 *
	 * The control: without it, a handler that admitted everything would pass the
	 * tests below while enforcing nothing at all.
	 *
	 * @return void
	 */
	public function testANamedActionStillRefusesSomebodyOutsideIt(): void {
		$this->assertFalse(
			$this->handler()->canReadProperty($this->schemaWith(['read' => ['hr']]), 'salaris', [])
		);
	}//end testANamedActionStillRefusesSomebodyOutsideIt()

	/**
	 * 🔑 AN ACTION THE BLOCK DOES NOT NAME HAS NO OPINION HERE.
	 *
	 * It is not "anyone may do it": the object cascade still governs the write.
	 * This layer is a narrowing, and a narrowing that says nothing narrows
	 * nothing.
	 *
	 * @return void
	 */
	public function testAnUnnamedActionFallsThroughRatherThanRefusing(): void {
		$this->assertTrue(
			$this->handler()->canUpdateProperty($this->schemaWith(['read' => ['hr']]), 'salaris', []),
			'Refusing here would make every action a partial block does not name unwritable, '
			. 'and all eight property blocks in the fleet are partial.'
		);
	}//end testAnUnnamedActionFallsThroughRatherThanRefusing()

	/**
	 * An explicitly empty action reads the same as an absent one, here.
	 *
	 * At schema level these differ, because there an empty list is the last
	 * word. Here neither narrows anything, so both fall through.
	 *
	 * @return void
	 */
	public function testAnExplicitlyEmptyActionAlsoFallsThrough(): void {
		$this->assertTrue(
			$this->handler()->canUpdateProperty($this->schemaWith(['read' => ['hr'], 'update' => []]), 'salaris', [])
		);
	}//end testAnExplicitlyEmptyActionAlsoFallsThrough()

	/**
	 * A property with no block at all is untouched.
	 *
	 * @return void
	 */
	public function testAPropertyWithNoBlockIsUntouched(): void {
		$schema = new Schema();
		$schema->setProperties(['naam' => ['type' => 'string']]);

		$this->assertTrue($this->handler()->canReadProperty($schema, 'naam', []));
	}//end testAPropertyWithNoBlockIsUntouched()
}//end class
