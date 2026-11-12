<?php

/**
 * BulkSlugCaller unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\BulkSlugCaller;
use PHPUnit\Framework\TestCase;

/**
 * Which table a bulk row is written to, given what the caller passed.
 */
final class BulkSlugCallerTest extends TestCase {

	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end register()

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}//end schema()

	public function testWithACallerSchemaEveryRowGoesToTheCallersTable(): void {
		$caller = new BulkSlugCaller($this->register(5), $this->schema(12));

		$this->assertSame([5, 12], $caller->idsOf(['register' => 3, 'schema' => 99]));
	}

	public function testWithACallerSchemaAndNoRegisterTheRowsRegisterCounts(): void {
		$caller = new BulkSlugCaller(null, '12');

		$this->assertSame([3, '12'], $caller->idsOf(['register' => 3, 'schema' => 99]));
		$this->assertSame([null, '12'], $caller->idsOf([]));
	}

	public function testWithoutACallerSchemaEachRowNamesItsOwn(): void {
		$caller = new BulkSlugCaller(7, null);

		$this->assertSame([3, 99], $caller->idsOf(['register' => 3, 'schema' => 99]));
		$this->assertSame([7, null], $caller->idsOf([]));
		$this->assertSame([7, null], $caller->idsOf('scalar'));
	}

	public function testOnlyTheCallersOwnEntityIsHandedToItsGroup(): void {
		$register = $this->register(5);
		$schema = $this->schema(12);
		$caller = new BulkSlugCaller($register, $schema);

		$this->assertSame($register, $caller->registerFor('5'));
		$this->assertNull($caller->registerFor('6'));
		$this->assertSame($schema, $caller->schemaFor('12'));
		$this->assertNull($caller->schemaFor('13'));

		$idsOnly = new BulkSlugCaller(5, 12);
		$this->assertNull($idsOnly->registerFor('5'));
		$this->assertNull($idsOnly->schemaFor('12'));
	}
}//end class
