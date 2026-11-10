<?php

/**
 * SchemaGrantEvaluatorMatchGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use OCA\OpenRegister\Db\Schema\GrantActor;
use OCA\OpenRegister\Db\Schema\GrantObject;
use OCA\OpenRegister\Db\Schema\GrantQuery;
use OCA\OpenRegister\Db\Schema\SchemaGrantEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of a conditional grant's match clause.
 */
class SchemaGrantEvaluatorMatchGoldenTest extends TestCase {

	/**
	 * Whether `editors` may read an object, under one match clause.
	 *
	 * @param array       $match              The match clause.
	 * @param array|null  $data               The object data.
	 * @param string|null $objectOrganisation The object's organisation.
	 * @param string|null $activeOrganisation The caller's active organisation.
	 *
	 * @return bool
	 */
	private function allows(array $match, ?array $data, ?string $objectOrganisation, ?string $activeOrganisation): bool {
		return (new SchemaGrantEvaluator())->allows(
			['read' => [['group' => 'editors', 'match' => $match]]],
			new GrantQuery('editors', 'read', new GrantActor('u-1', null, $activeOrganisation), new GrantObject(null, $data, $objectOrganisation))
		);
	}

	/**
	 * Every outcome, allowed and refused.
	 *
	 * @return array<string, array{0: array, 1: array|null, 2: string|null, 3: string|null, 4: bool}>
	 */
	public static function outcomes(): array {
		return [
			'plain field equal'                       => [['status' => 'open'], ['status' => 'open'], null, null, true],
			'plain field differs'                     => [['status' => 'open'], ['status' => 'closed'], null, null, false],
			'plain field missing'                     => [['status' => 'open'], [], null, null, false],
			'null expected matches missing field'     => [['status' => null], [], null, null, true],
			'relation compares by id'                 => [['zaak' => 'z-1'], ['zaak' => ['id' => 'z-1']], null, null, true],
			'relation with other id'                  => [['zaak' => 'z-1'], ['zaak' => ['id' => 'z-2']], null, null, false],
			'organisation field'                      => [['_organisation' => 'org-a'], [], 'org-a', null, true],
			'organisation field differs'              => [['_organisation' => 'org-a'], [], 'org-b', null, false],
			'organisation variable on own org'        => [['_organisation' => '$organisation'], [], 'org-a', 'org-a', true],
			'organisation variable on other org'      => [['_organisation' => '$organisation'], [], 'org-b', 'org-a', false],
			'organisation variable without active'    => [['_organisation' => '$organisation'], [], 'org-a', null, false],
			'organisation variable, null both sides'  => [['_organisation' => '$organisation'], [], null, null, false],
			'organisation variable on a data field'   => [['owner_org' => '$organisation'], ['owner_org' => 'org-a'], null, 'org-a', true],
			'all conditions must hold'                => [['status' => 'open', '_organisation' => '$organisation'], ['status' => 'open'], 'org-b', 'org-a', false],
			'later condition fails after org resolves' => [['_organisation' => '$organisation', 'status' => 'open'], ['status' => 'closed'], 'org-a', 'org-a', false],
			'every condition holds'                   => [['_organisation' => '$organisation', 'status' => 'open'], ['status' => 'open'], 'org-a', 'org-a', true],
			'variable never matches its own spelling' => [['_organisation' => '$organisation'], [], '$organisation', null, false],
			'literal is not a variable'               => [['status' => '$organisationX'], ['status' => '$organisationX'], null, null, true],
		];
	}

	/**
	 * @dataProvider outcomes
	 */
	public function testMatchOutcome(array $match, ?array $data, ?string $objectOrganisation, ?string $activeOrganisation, bool $expected): void {
		$this->assertSame($expected, $this->allows($match, $data, $objectOrganisation, $activeOrganisation));
	}

	/**
	 * Mutation check: a caller outside the granted group is refused whatever the match.
	 */
	public function testAnotherGroupIsRefusedEvenWhenTheMatchHolds(): void {
		$allowed = (new SchemaGrantEvaluator())->allows(
			['read' => [['group' => 'editors', 'match' => ['status' => 'open']]]],
			new GrantQuery('viewers', 'read', new GrantActor('u-1', null, 'org-a'), new GrantObject(null, ['status' => 'open'], null))
		);

		$this->assertFalse($allowed);
	}
}
