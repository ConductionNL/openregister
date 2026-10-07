<?php

declare(strict_types=1);

/**
 * The reference-filter operand check, and the call site it shipped without.
 *
 * `ReferenceFilterDeclaration::assertOperandsExist()` was fully implemented,
 * with a message for each of its two refusals, and nothing anywhere called
 * it. The comment beside `PropertyValidatorHandler::validateProperty()` said
 * the operands were checked "in SchemasController", and that class did not
 * mention the declaration at all, so the sentence stopped anybody looking
 * while the check was dead code. An author saving a filter that reads a
 * property their schema does not declare got a clean 201 and found out later
 * from a picker that silently offered everything.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterException;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterOperandGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReferenceFilterOperandGuard.
 */
class ReferenceFilterOperandGuardTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private ReferenceFilterOperandGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->guard = new ReferenceFilterOperandGuard(schemaMapper: $this->schemaMapper);
	}//end setUp()

	/**
	 * A reference property carrying one filter condition.
	 *
	 * @param string $from  The property this record is read for.
	 * @param string $field The property matched on the referenced schema.
	 *
	 * @return array<string, mixed> The properties block.
	 */
	private function propertiesFiltering(string $from, string $field): array {
		return [
			'municipality' => ['type' => 'string'],
			'caseType' => [
				'$ref' => 'case-type',
				'x-openregister-reference-filter' => [
					['field' => $field, 'op' => 'eq', 'from' => $from],
				],
			],
		];
	}//end propertiesFiltering()

	/**
	 * A referenced schema declaring the given properties.
	 *
	 * @param array<string, mixed> $properties Its properties.
	 *
	 * @return void
	 */
	private function referencedSchemaDeclares(array $properties): void {
		$schema = new Schema();
		$schema->setProperties($properties);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end referencedSchemaDeclares()

	/**
	 * The filter reads a property this schema does not declare, which is the
	 * half a picker can never report: the record has nothing to read from.
	 *
	 * @return void
	 */
	public function testAFilterReadingAPropertyThisSchemaDoesNotDeclareIsRefused(): void {
		$this->referencedSchemaDeclares(['municipality' => ['type' => 'string']]);

		$this->expectException(ReferenceFilterException::class);
		$this->expectExceptionMessage('this schema does not declare it');

		$this->guard->assertProperties(
			properties: $this->propertiesFiltering(from: 'gemeente', field: 'municipality')
		);
	}//end testAFilterReadingAPropertyThisSchemaDoesNotDeclareIsRefused()

	/**
	 * The other half: the far schema has no such property to match on.
	 *
	 * @return void
	 */
	public function testAFilterMatchingAPropertyTheReferencedSchemaLacksIsRefused(): void {
		$this->referencedSchemaDeclares(['name' => ['type' => 'string']]);

		$this->expectException(ReferenceFilterException::class);
		$this->expectExceptionMessage('the referenced schema does not declare it');

		$this->guard->assertProperties(
			properties: $this->propertiesFiltering(from: 'municipality', field: 'municipality')
		);
	}//end testAFilterMatchingAPropertyTheReferencedSchemaLacksIsRefused()

	/**
	 * The control. A filter whose operands are both declared saves, and
	 * without it the two refusals above would pass just as well on a guard
	 * that refused everything.
	 *
	 * @return void
	 */
	public function testAFilterWhoseOperandsBothExistIsAccepted(): void {
		$this->referencedSchemaDeclares(['municipality' => ['type' => 'string']]);

		$this->guard->assertProperties(
			properties: $this->propertiesFiltering(from: 'municipality', field: 'municipality')
		);

		$this->addToAssertionCount(1);
	}//end testAFilterWhoseOperandsBothExistIsAccepted()

	/**
	 * A target that does not resolve is not a refusal. A schema may reference
	 * one that has not been imported yet, and refusing here would make the
	 * order of an import decide whether a schema saves. This side is still
	 * checked.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTargetLeavesTheFarSideUncheckedAndStillChecksThisOne(): void {
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('not imported'));

		$this->guard->assertProperties(
			properties: $this->propertiesFiltering(from: 'municipality', field: 'anything-at-all')
		);

		$this->expectException(ReferenceFilterException::class);
		$this->guard->assertProperties(
			properties: $this->propertiesFiltering(from: 'gemeente', field: 'anything-at-all')
		);
	}//end testAnUnresolvableTargetLeavesTheFarSideUncheckedAndStillChecksThisOne()

	/**
	 * A property with no filter is not sent to the mapper at all: resolving a
	 * schema per property would put a query on every save of every schema.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutAFilterIsNotResolved(): void {
		$this->schemaMapper->expects($this->never())->method('find');

		$this->guard->assertProperties(
			properties: [
				'municipality' => ['type' => 'string'],
				'caseType' => ['$ref' => 'case-type'],
			]
		);

		$this->addToAssertionCount(1);
	}//end testAPropertyWithoutAFilterIsNotResolved()
}//end class
