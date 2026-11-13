<?php

/**
 * A grant row set folds duplicate rows into one and strips a revoked verb.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\GrantRowSet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\GrantRowSet
 */
class GrantRowSetTest extends TestCase {

	private function row(array $overrides = []): array {
		return array_merge(
			[
				'id'            => 'row',
				'origin'        => 'shared',
				'level'         => 'object',
				'role'          => null,
				'principal'     => ['type' => 'user', 'id' => 'alice'],
				'inheritedFrom' => null,
				'verbs'         => ['read'],
				'matchedOn'     => ['share'],
				'relative'      => true,
			],
			$overrides
		);
	}

	public function testTheEnvelopeIsKeptAsGiven(): void {
		$set = new GrantRowSet(['object' => 'uuid-1']);

		$this->assertSame(['object' => 'uuid-1'], $set->envelope);
		$this->assertSame([], $set->rows());
	}

	public function testTwoRowsForTheSameHolderMergeVerbsAndMatches(): void {
		$set = new GrantRowSet([]);
		$set->fold($this->row(['verbs' => ['read', 'update'], 'matchedOn' => ['share']]));
		$set->fold($this->row(['verbs' => ['update', 'delete'], 'matchedOn' => ['share', 'group']]));

		$rows = array_values($set->rows());
		$this->assertCount(1, $rows);
		$this->assertSame(['read', 'update', 'delete'], $rows[0]['verbs']);
		$this->assertSame(['share', 'group'], $rows[0]['matchedOn']);
	}

	public function testAMergedRowIsRelativeOnlyWhenBothAre(): void {
		$set = new GrantRowSet([]);
		$set->fold($this->row(['relative' => true]));
		$set->fold($this->row(['relative' => false]));

		$this->assertFalse(array_values($set->rows())[0]['relative']);

		$both = new GrantRowSet([]);
		$both->fold($this->row(['relative' => true]));
		$both->fold($this->row(['relative' => true]));

		$this->assertTrue(array_values($both->rows())[0]['relative']);
	}

	public function testRowsDifferingInAnyKeyPartStaySeparate(): void {
		$set = new GrantRowSet([]);
		$set->fold($this->row());
		$set->fold($this->row(['level' => 'schema']));
		$set->fold($this->row(['principal' => ['type' => 'group', 'id' => 'alice']]));
		$set->fold($this->row(['inheritedFrom' => ['uuid' => 'parent']]));
		$set->fold($this->row(['role' => 'editor']));

		$this->assertCount(5, $set->rows());
	}

	public function testARuleRowGetsAnIdFromItsNonNullParts(): void {
		$set = new GrantRowSet([]);
		$set->fold($this->row(['origin' => 'rule', 'level' => 'schema', 'role' => null, 'principal' => ['type' => 'group', 'id' => 'staff']]));
		$set->fold($this->row(['origin' => 'rule', 'level' => 'register', 'role' => 'lead', 'principal' => ['type' => 'user', 'id' => 'bob']]));

		$ids = array_column(array_values($set->rows()), 'id');
		$this->assertSame(['rule:schema:group:staff', 'rule:register:user:bob:lead'], $ids);
	}

	public function testANonRuleRowKeepsItsOwnId(): void {
		$set = new GrantRowSet([]);
		$set->fold($this->row(['id' => 'share-42']));

		$this->assertSame('share-42', array_values($set->rows())[0]['id']);
	}

	public function testStripRemovesTheVerbFromThatPrincipalOnly(): void {
		$set = new GrantRowSet([]);
		$set->fold($this->row(['verbs' => ['read', 'update']]));
		$set->fold($this->row(['principal' => ['type' => 'user', 'id' => 'bob'], 'verbs' => ['read', 'update']]));
		$set->fold($this->row(['principal' => ['type' => 'group', 'id' => 'alice'], 'verbs' => ['update']]));

		$set->strip(['user', 'alice'], 'update');

		$verbs = array_map(
			static fn (array $row): array => [$row['principal']['type'].':'.$row['principal']['id'] => $row['verbs']],
			array_values($set->rows())
		);
		$this->assertSame(
			[['user:alice' => ['read']], ['user:bob' => ['read', 'update']], ['group:alice' => ['update']]],
			$verbs
		);
	}

	public function testARowLeftWithNoVerbsIsDropped(): void {
		$set = new GrantRowSet([]);
		$set->fold($this->row(['verbs' => ['read']]));
		$set->fold($this->row(['level' => 'schema', 'verbs' => ['read', 'delete']]));

		$set->strip(['user', 'alice'], 'read');

		$rows = array_values($set->rows());
		$this->assertCount(1, $rows);
		$this->assertSame('schema', $rows[0]['level']);
		$this->assertSame(['delete'], $rows[0]['verbs']);
	}
}
