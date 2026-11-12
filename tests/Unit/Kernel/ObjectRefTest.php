<?php

/**
 * ObjectRef and RegisterSchemaRef carry a request's register, schema and object id unchanged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use PHPUnit\Framework\TestCase;

class ObjectRefTest extends TestCase {

	/**
	 * The pair keeps each value and its type as the request carried it.
	 *
	 * @return void
	 */
	public function testRegisterSchemaRefKeepsTheValuesAsGiven(): void {
		$ref = new RegisterSchemaRef(register: 'publications', schema: 7);

		$this->assertSame('publications', $ref->register);
		$this->assertSame(7, $ref->schema);
	}//end testRegisterSchemaRefKeepsTheValuesAsGiven()

	/**
	 * The object reference keeps its three values.
	 *
	 * @return void
	 */
	public function testObjectRefKeepsTheValuesAsGiven(): void {
		$ref = new ObjectRef(register: 3, schema: 'document', id: 'b6f1c1d2-0000-4000-8000-000000000001');

		$this->assertSame(3, $ref->register);
		$this->assertSame('document', $ref->schema);
		$this->assertSame('b6f1c1d2-0000-4000-8000-000000000001', $ref->id);
	}//end testObjectRefKeepsTheValuesAsGiven()

	/**
	 * registerSchema() drops the object id and keeps the pair.
	 *
	 * @return void
	 */
	public function testRegisterSchemaIsThePairWithoutTheId(): void {
		$pair = (new ObjectRef(register: 'publications', schema: 12, id: '42'))->registerSchema();

		$this->assertEquals(new RegisterSchemaRef(register: 'publications', schema: 12), $pair);
		$this->assertSame(12, $pair->schema);
	}//end testRegisterSchemaIsThePairWithoutTheId()
}//end class
