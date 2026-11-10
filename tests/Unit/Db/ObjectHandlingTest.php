<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectHandling;
use PHPUnit\Framework\TestCase;

/**
 * Which handling modes store a UUID reference.
 *
 * This predicate decides the COLUMN TYPE of an object-typed property. Get it wrong
 * and the property gets a `json` column while the save path writes a bare UUID string
 * into it — PostgreSQL then rejects every save with
 *
 *     SQLSTATE[22P02]: invalid input syntax for type json
 *     DETAIL: Token "b25f5f9c" is invalid.
 *
 * which reached the user as "You do not have permission to perform this action".
 * That is exactly what `related-schema` did: it is the mode the docs describe and the
 * schema editor offers, and the write path had never heard of it.
 */
class ObjectHandlingTest extends TestCase {

	/**
	 * `related-schema` is the DOCUMENTED mode for a UUID reference — the one that was
	 * broken. `related-object` is the undocumented alias the code happened to support.
	 *
	 * @return void
	 */
	public function testRelatingModesBothStoreAUuidReference(): void {
		$this->assertTrue(ObjectHandling::relates('related-schema'));
		$this->assertTrue(ObjectHandling::relates('related-object'));

	}//end testRelatingModesBothStoreAUuidReference()

	/**
	 * Nesting modes embed the object; they must NOT get a UUID column.
	 *
	 * @return void
	 */
	public function testNestingModesDoNotRelate(): void {
		$this->assertFalse(ObjectHandling::relates('nested-object'));
		$this->assertFalse(ObjectHandling::relates('nested-schema'));
		$this->assertFalse(ObjectHandling::relates('uri'));

	}//end testNestingModesDoNotRelate()

	/**
	 * An unset or unknown handling must not be treated as relating — that would hand a
	 * VARCHAR column to a property that stores a whole object.
	 *
	 * @return void
	 */
	public function testUnsetOrUnknownHandlingDoesNotRelate(): void {
		$this->assertFalse(ObjectHandling::relates(null));
		$this->assertFalse(ObjectHandling::relates(''));
		$this->assertFalse(ObjectHandling::relates('cascade'));
		$this->assertFalse(ObjectHandling::relates('Related-Schema'));

	}//end testUnsetOrUnknownHandlingDoesNotRelate()

}//end class
