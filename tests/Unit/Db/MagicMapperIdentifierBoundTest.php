<?php

/**
 * A register or schema bound that arrives as a list must not collapse to 1.
 *
 * `MagicMapper::searchObjects()` read its register and schema out of the query
 * and cast them with `(int)`. A view-scoped search does not hand it scalars:
 * ViewScopeApplier merges a view's registers and schemas as LISTS, and `(int)`
 * on a non-empty array is 1 — silently, with no warning and no error. So a
 * search bounded by a view reading register 7 ran against register 1 instead.
 *
 * On an anonymous access link that is not a display bug. The link runs with
 * `_rbac: false` and `_multitenancy: false` because the link itself is the
 * authorization, which leaves the view as the only bound on the query. A bound
 * that silently rewrites itself to "register 1, schema 1" is a read of whatever
 * happens to live there, in any organisation.
 *
 * These reach the private helpers by reflection on purpose. The behaviour under
 * test is a pure decision about the identifier — one id, no id, or several —
 * and the alternative is standing up a mapper with its full constructor and a
 * live query builder to observe a conversion that happens before any of it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MagicMapperIdentifierBoundTest extends TestCase {

	/**
	 * Call one of the mapper's private identifier helpers.
	 *
	 * @param string $method The helper to call.
	 * @param array<int, mixed> $args Its arguments.
	 *
	 * @return mixed What the helper answered.
	 */
	private function call(string $method, array $args): mixed {
		$class = new ReflectionClass(MagicMapper::class);
		$mapper = $class->newInstanceWithoutConstructor();

		return $class->getMethod($method)->invokeArgs($mapper, $args);
	}//end call()

	public function testAListHoldingOneRegisterResolvesToThatRegisterNotToOne(): void {
		// THE REGRESSION TEST. `(int)[7]` is 1, so this returned register 1.
		$this->assertSame(7, $this->call('soleIdentifier', [[7]]));
	}//end testAListHoldingOneRegisterResolvesToThatRegisterNotToOne()

	public function testAPlainIdentifierIsUnchanged(): void {
		$this->assertSame(7, $this->call('soleIdentifier', [7]));
	}//end testAPlainIdentifierIsUnchanged()

	public function testANonNumericIdentifierSurvivesInsteadOfBecomingZero(): void {
		// The mappers resolve an id, a uuid OR a slug. `(int)` made every uuid 0.
		$this->assertSame(
			'0a1b2c3d-4e5f-6071-8293-a4b5c6d7e8f9',
			$this->call('soleIdentifier', ['0a1b2c3d-4e5f-6071-8293-a4b5c6d7e8f9'])
		);
	}//end testANonNumericIdentifierSurvivesInsteadOfBecomingZero()

	public function testAListHoldingSeveralIdentifiersIsRefusedRatherThanGuessed(): void {
		// Null sends the caller down its own fail-closed branch, which logs and
		// answers with nothing. Answering for the first, or for 1, would be a
		// bound the view never asked for.
		$this->assertNull($this->call('soleIdentifier', [[7, 8]]));
	}//end testAListHoldingSeveralIdentifiersIsRefusedRatherThanGuessed()

	public function testAnEmptyListIsRefused(): void {
		$this->assertNull($this->call('soleIdentifier', [[]]));
	}//end testAnEmptyListIsRefused()

	public function testAGappedListStillReadsAsOneIdentifier(): void {
		// array_unique() preserves keys, so a merged bound can arrive gapped.
		$this->assertSame(7, $this->call('soleIdentifier', [[3 => 7]]));
	}//end testAGappedListStillReadsAsOneIdentifier()

	public function testSeveralSchemasSendTheSearchDownTheMultiSchemaPath(): void {
		$this->assertTrue($this->call('namesSeveral', [[7], [4, 5]]));
	}//end testSeveralSchemasSendTheSearchDownTheMultiSchemaPath()

	public function testSeveralRegistersWithOneSchemaAlsoTakeThatPath(): void {
		$this->assertTrue($this->call('namesSeveral', [[7, 8], [4]]));
	}//end testSeveralRegistersWithOneSchemaAlsoTakeThatPath()

	public function testOneOfEachStaysOnTheSingleTablePath(): void {
		$this->assertFalse($this->call('namesSeveral', [[7], [4]]));
	}//end testOneOfEachStaysOnTheSingleTablePath()

	public function testRegistersWithoutASchemaHaveNothingToUnionSoAreNotSentThere(): void {
		// The UNION path resolves one table per schema. With no schema named it
		// would union nothing; the caller's fail-closed branch is the right answer.
		$this->assertFalse($this->call('namesSeveral', [[7, 8], null]));
	}//end testRegistersWithoutASchemaHaveNothingToUnionSoAreNotSentThere()

	public function testTheIdentifierListKeepsOnlyNumericIdsAndDeduplicates(): void {
		$this->assertSame([7, 8], $this->call('identifierList', [[7, '8', 7, 'not-an-id']]));
	}//end testTheIdentifierListKeepsOnlyNumericIdsAndDeduplicates()
}//end class
