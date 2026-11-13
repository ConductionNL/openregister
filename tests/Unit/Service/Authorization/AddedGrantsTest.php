<?php

/**
 * Which actions an authorization edit gives to someone new.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Service\Authorization\AddedGrants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AddedGrants}.
 */
class AddedGrantsTest extends TestCase {

	/**
	 * A group added to an action's rule grants that action.
	 *
	 * @return void
	 */
	public function testAGroupAddedToAnActionGrantsIt(): void {
		$this->assertSame(
			['delete'],
			(new AddedGrants())->between(
				old: ['read' => ['redactie'], 'delete' => ['beheer']],
				new: ['read' => ['redactie'], 'delete' => ['beheer', 'redactie']]
			)
		);
	}//end testAGroupAddedToAnActionGrantsIt()

	/**
	 * A new action key grants that action.
	 *
	 * @return void
	 */
	public function testANewActionGrantsIt(): void {
		$this->assertSame(['update'], (new AddedGrants())->between(old: ['read' => ['redactie']], new: ['read' => ['redactie'], 'update' => ['redactie']]));
	}//end testANewActionGrantsIt()

	/**
	 * Keeping, reordering or removing entries grants nothing.
	 *
	 * @return void
	 */
	public function testKeepingReorderingOrRemovingGrantsNothing(): void {
		$old = ['read' => ['a', 'b'], 'delete' => ['beheer', 'redactie']];

		$this->assertSame([], (new AddedGrants())->between(old: $old, new: $old));
		$this->assertSame([], (new AddedGrants())->between(old: $old, new: ['read' => ['b', 'a'], 'delete' => ['beheer', 'redactie']]));
		$this->assertSame([], (new AddedGrants())->between(old: $old, new: ['read' => ['a']]));
	}//end testKeepingReorderingOrRemovingGrantsNothing()

	/**
	 * A rule object is compared by content, not by key order; a changed rule is a new grant.
	 *
	 * @return void
	 */
	public function testARuleObjectIsComparedByContent(): void {
		$old = ['update' => [['group' => 'redactie', 'match' => ['assignee' => '$userId']]]];

		$this->assertSame([], (new AddedGrants())->between(old: $old, new: ['update' => [['match' => ['assignee' => '$userId'], 'group' => 'redactie']]]));
		$this->assertSame(['update'], (new AddedGrants())->between(old: $old, new: ['update' => [['group' => 'redactie']]]));
	}//end testARuleObjectIsComparedByContent()

	/**
	 * A block sent as JSON text is read as its decoded form, and no block grants nothing.
	 *
	 * @return void
	 */
	public function testJsonTextAndNullBlocksAreRead(): void {
		$this->assertSame(['manage'], (new AddedGrants())->between(old: '{"read":["a"]}', new: '{"read":["a"],"manage":["a"]}'));
		$this->assertSame(['read'], (new AddedGrants())->between(old: null, new: ['read' => ['a']]));
		$this->assertSame([], (new AddedGrants())->between(old: ['read' => ['a']], new: null));
	}//end testJsonTextAndNullBlocksAreRead()

	/**
	 * A key whose value is not a list of rules, such as a role matrix, is not an action rule.
	 *
	 * @return void
	 */
	public function testAKeyThatIsNotAnActionListIsIgnored(): void {
		$this->assertSame([], (new AddedGrants())->between(old: [], new: ['matrix' => ['field' => 'role', 'rows' => []], 0 => ['x']]));
	}//end testAKeyThatIsNotAnActionListIsIgnored()
}//end class
