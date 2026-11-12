<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\EncryptedOrderRefusal;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldSortException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\EncryptedOrderRefusal
 * @covers \OCA\OpenRegister\Exception\EncryptedFieldSortException
 * @covers \OCA\OpenRegister\Exception\EncryptedFieldFilterException
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 */
class EncryptedOrderRefusalTest extends TestCase {
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	private function encrypted(string $name): Schema {
		return $this->schema([$name => ['type' => 'string', 'x-openregister-encrypted' => true], 'title' => ['type' => 'string']]);
	}//end encrypted()

	/**
	 * @dataProvider everyForm
	 */
	public function testEveryFormOfOrderIsRefused(mixed $order): void {
		$this->expectException(EncryptedFieldSortException::class);
		$this->expectExceptionMessage('Property "bsn" is encrypted at rest and cannot be sorted on.');
		$this->expectExceptionCode(400);

		(new EncryptedOrderRefusal(order: $order))->check(schemas: [$this->encrypted(name: 'bsn')]);
	}//end testEveryFormOfOrderIsRefused()

	public static function everyForm(): array {
		return [
			'map' => [['bsn' => 'asc']],
			'second key' => [['title' => 'asc', 'bsn' => 'desc']],
			'json map' => ['{"bsn":"desc"}'],
			'list of objects' => ['[{"field":"bsn","direction":"DESC"}]'],
			'list of names' => [['bsn:desc']],
			'one name' => ['bsn'],
		];
	}//end everyForm()

	public function testASecondSchemaIsChecked(): void {
		$plain = $this->schema(['bsn' => ['type' => 'string']]);

		try {
			(new EncryptedOrderRefusal(order: ['bsn' => 'asc']))->check(schemas: [$plain, $this->encrypted(name: 'bsn')]);
			$this->fail('not refused');
		} catch (EncryptedFieldSortException $refusal) {
			$this->assertSame('bsn', $refusal->getProperty());
			$this->assertSame('encrypted-field-not-sortable', $refusal->getReason());
		}
	}//end testASecondSchemaIsChecked()

	public function testADigitOnlyPropertyIsRefused(): void {
		$this->expectException(EncryptedFieldSortException::class);

		(new EncryptedOrderRefusal(order: [7 => 'asc']))->check(schemas: [$this->encrypted(name: '7')]);
	}//end testADigitOnlyPropertyIsRefused()

	/**
	 * @dataProvider allowedOrders
	 */
	public function testAnOrderOnAnythingElsePasses(mixed $order): void {
		(new EncryptedOrderRefusal(order: $order))->check(schemas: [$this->encrypted(name: 'bsn')]);

		$this->addToAssertionCount(1);
	}//end testAnOrderOnAnythingElsePasses()

	public static function allowedOrders(): array {
		return [
			'plain property' => [['title' => 'asc']],
			'metadata of that name' => [['@self.bsn' => 'asc']],
			'another spelling' => [['BSN' => 'asc']],
			'nothing' => [[]],
		];
	}//end allowedOrders()
}//end class
