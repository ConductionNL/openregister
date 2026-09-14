<?php

/**
 * The two contradictions a deny makes possible, caught at save.
 *
 * Both are cheap here and expensive later. A principal granted and denied the
 * same verb at one level resolves silently either way, and the author and the
 * resolver only ever meet in a support ticket. A register whose `manage` verb
 * has been denied to the last principal holding it cannot have its own access
 * rules edited again, and is recoverable only from the database.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the save-time refusals.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator
 */
class AuthorizationDenyValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var AuthorizationDenyValidator
	 */
	private AuthorizationDenyValidator $validator;

	/**
	 * Build the validator over the shared deny resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new AuthorizationDenyValidator(new DenyResolver());
	}//end setUp()

	/**
	 * A block that declares no deny is storable, whatever else it says.
	 *
	 * @return void
	 */
	public function testABlockWithoutADenyIsStorable(): void {
		$this->assertSame([], $this->validator->findings(null, 'the schema "zaak"'));
		$this->assertSame([], $this->validator->findings([], 'the schema "zaak"'));
		$this->assertSame(
			[],
			$this->validator->findings(['read' => ['behandelaars'], 'manage' => ['beheerders']], 'the schema "zaak"')
		);
	}//end testABlockWithoutADenyIsStorable()

	/**
	 * A grant and a deny of one verb to one principal is refused, and BOTH are named.
	 *
	 * @return void
	 */
	public function testAGrantAndADenyAtOneLevelIsRefusedAndBothAreNamed(): void {
		$block = [
			'delete' => ['behandelaars'],
			'deny' => ['delete' => ['behandelaars']],
		];

		$findings = $this->validator->findings($block, 'the schema "zaak"');

		$this->assertCount(1, $findings);
		$this->assertStringContainsString('delete', $findings[0]);
		$this->assertStringContainsString('behandelaars', $findings[0]);
		$this->assertStringContainsString('deny', $findings[0]);
	}//end testAGrantAndADenyAtOneLevelIsRefusedAndBothAreNamed()

	/**
	 * The refusal carries HTTP 422, not 400 and not 500.
	 *
	 * @return void
	 */
	public function testTheRefusalCarries422(): void {
		$block = ['delete' => ['behandelaars'], 'deny' => ['delete' => ['behandelaars']]];

		try {
			$this->validator->assertStorable($block, 'the schema "zaak"');
			$this->fail('The block should not have been storable.');
		} catch (AuthorizationBlockException $e) {
			$this->assertSame(422, $e->getHttpStatusCode());
			$this->assertStringContainsString('behandelaars', $e->getMessage());
		}
	}//end testTheRefusalCarries422()

	/**
	 * A deny of a verb that is granted to somebody ELSE is a normal rule.
	 *
	 * The control: without it, a validator that refused every deny would pass
	 * the collision test above.
	 *
	 * @return void
	 */
	public function testADenyOfAVerbGrantedToAnotherPrincipalIsStorable(): void {
		$block = [
			'delete' => ['behandelaars'],
			'deny' => ['delete' => ['stagiairs']],
		];

		$this->assertSame([], $this->validator->findings($block, 'the schema "zaak"'));
	}//end testADenyOfAVerbGrantedToAnotherPrincipalIsStorable()

	/**
	 * The collision is found through the object entry form too.
	 *
	 * @return void
	 */
	public function testTheCollisionIsFoundThroughTheObjectEntryForm(): void {
		$block = [
			'update' => [['group' => 'behandelaars', 'match' => ['status' => 'open']]],
			'deny' => ['update' => [['group' => 'behandelaars']]],
		];

		$findings = $this->validator->findings($block, 'the schema "zaak"');

		$this->assertCount(1, $findings);
		$this->assertStringContainsString('behandelaars', $findings[0]);
	}//end testTheCollisionIsFoundThroughTheObjectEntryForm()

	/**
	 * Denying `manage` to the last principal holding it is refused, by name.
	 *
	 * @return void
	 */
	public function testTheLastManagerCannotBeDeniedAway(): void {
		$block = [
			'manage' => ['beheerders'],
			'deny' => ['manage' => ['beheerders']],
		];

		$findings = $this->validator->findings($block, 'the register "zaken"');

		$this->assertNotEmpty($findings);
		$joined = implode(' ', $findings);
		$this->assertStringContainsString('beheerders', $joined);
		$this->assertStringContainsString('zaken', $joined);
		$this->assertStringContainsString('manage', $joined);
	}//end testTheLastManagerCannotBeDeniedAway()

	/**
	 * A deny of `manage` leaves administration standing when somebody else holds it.
	 *
	 * Note what this block does NOT do: it does not name `beheerders` on the
	 * grant side. Naming a principal on both sides of one block is the
	 * collision above, and the two rules compose — at one level you can never
	 * deny a principal the same block grants, whatever the verb.
	 *
	 * @return void
	 */
	public function testDenyingManageWhileAnotherPrincipalHoldsItIsStorable(): void {
		$block = [
			'manage' => ['directie'],
			'deny' => ['manage' => ['beheerders']],
		];

		$findings = $this->validator->findings($block, 'the register "zaken"');

		$this->assertSame([], $findings);
	}//end testDenyingManageWhileAnotherPrincipalHoldsItIsStorable()

	/**
	 * A deny of `manage` where nobody was granted it is refused too.
	 *
	 * A block that denies administration and grants it nowhere leaves the same
	 * register unadministrable, and says so before it is stored rather than
	 * after.
	 *
	 * @return void
	 */
	public function testDenyingManageNobodyHoldsIsAlsoRefused(): void {
		$block = ['read' => ['iedereen'], 'deny' => ['manage' => ['beheerders']]];

		$this->assertNotEmpty($this->validator->findings($block, 'the register "zaken"'));
	}//end testDenyingManageNobodyHoldsIsAlsoRefused()

	/**
	 * `roles` is a role map, not a verb, and is never read as one.
	 *
	 * @return void
	 */
	public function testTheRolesKeyIsNotReadAsAVerb(): void {
		$block = [
			'roles' => ['waarnemer' => ['waarnemers']],
			'deny' => ['roles' => ['waarnemer' => ['waarnemers']]],
		];

		$this->assertSame([], $this->validator->findings($block, 'the schema "zaak"'));
	}//end testTheRolesKeyIsNotReadAsAVerb()

	/**
	 * Every contradiction is reported at once, not one per round trip.
	 *
	 * @return void
	 */
	public function testEveryContradictionIsReportedAtOnce(): void {
		$block = [
			'read' => ['behandelaars'],
			'update' => ['behandelaars'],
			'deny' => [
				'read' => ['behandelaars'],
				'update' => ['behandelaars'],
			],
		];

		$this->assertCount(2, $this->validator->findings($block, 'the schema "zaak"'));
	}//end testEveryContradictionIsReportedAtOnce()
}//end class
