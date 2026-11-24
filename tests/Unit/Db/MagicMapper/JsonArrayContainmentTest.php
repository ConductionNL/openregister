<?php

/**
 * The `contains` operator on an array property: one entry holding every
 * given key and value, on both platforms.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\JsonArrayContainment;
use PHPUnit\Framework\TestCase;

/**
 * Pins the operand reading and the SQL of the `contains` operator.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\JsonArrayContainment
 */
class JsonArrayContainmentTest extends TestCase {

	/**
	 * Only a value holding the `contains` key uses the operator.
	 *
	 * @return void
	 */
	public function testAppliesOnlyToABagHoldingContains(): void {
		$this->assertTrue(JsonArrayContainment::applies(value: ['contains' => ['system' => 'decos']]));
		$this->assertTrue(JsonArrayContainment::applies(value: ['contains' => null]));
		$this->assertFalse(JsonArrayContainment::applies(value: 'contains'));
		$this->assertFalse(JsonArrayContainment::applies(value: ['x', 'y']));
		$this->assertFalse(JsonArrayContainment::applies(value: ['in' => ['contains']]));
	}//end testAppliesOnlyToABagHoldingContains()

	/**
	 * The operand becomes a one-entry list, query-string booleans as booleans.
	 *
	 * @return void
	 */
	public function testTheOperandBecomesAOneEntryList(): void {
		$this->assertSame(
			'[{"system":"decos","identifier":"Z-2026/0412","public":true,"hidden":false,"n":3,"none":null}]',
			JsonArrayContainment::json(
				value: ['contains' => ['system' => 'decos', 'identifier' => 'Z-2026/0412', 'public' => 'true', 'hidden' => 'false', 'n' => 3, 'none' => null]]
			)
		);
		$this->assertSame('[{"public":true}]', JsonArrayContainment::json(value: ['contains' => ['public' => true]]));
		$this->assertSame('[{"naam":"Één"}]', JsonArrayContainment::json(value: ['contains' => ['naam' => 'Één']]));
	}//end testTheOperandBecomesAOneEntryList()

	/**
	 * An operand that is not a non-empty map of scalars gives no JSON.
	 *
	 * @return void
	 */
	public function testAnUnusableOperandGivesNoJson(): void {
		$this->assertNull(JsonArrayContainment::json(value: ['contains' => 'decos']));
		$this->assertNull(JsonArrayContainment::json(value: ['contains' => []]));
		$this->assertNull(JsonArrayContainment::json(value: ['contains' => ['a', 'b']]));
		$this->assertNull(JsonArrayContainment::json(value: ['contains' => ['system' => ['decos']]]));
		$this->assertNull(JsonArrayContainment::json(value: ['contains' => ['system' => 'decos', 3 => 'x']]));
		$this->assertNull(JsonArrayContainment::json(value: ['other' => ['system' => 'decos']]));
		$this->assertNull(JsonArrayContainment::json(value: "\xB1"));
		$this->assertNull(JsonArrayContainment::json(value: ['contains' => ['system' => "\xB1"]]));
	}//end testAnUnusableOperandGivesNoJson()

	/**
	 * PostgreSQL uses jsonb containment, MySQL and MariaDB JSON_CONTAINS.
	 *
	 * @return void
	 */
	public function testTheConditionPerPlatform(): void {
		$this->assertSame(
			"COALESCE(t.ids, '[]')::jsonb @> :p1::jsonb",
			JsonArrayContainment::condition(column: 't.ids', json: ':p1', isPostgres: true)
		);
		$this->assertSame(
			"JSON_CONTAINS(COALESCE(t.ids, '[]'), :p1)",
			JsonArrayContainment::condition(column: 't.ids', json: ':p1', isPostgres: false)
		);
	}//end testTheConditionPerPlatform()
}//end class
