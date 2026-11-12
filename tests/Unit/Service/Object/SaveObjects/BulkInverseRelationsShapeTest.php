<?php

/**
 * Which relation values the bulk inverse-relation pass links: a list
 * property links only a list value, and an inverse definition whose
 * `isArray` is not a boolean links nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use OCA\OpenRegister\Service\Object\SaveObjects\BulkInverseRelations;
use PHPUnit\Framework\TestCase;

/**
 * Value-shape tests for {@see BulkInverseRelations}.
 */
class BulkInverseRelationsShapeTest extends TestCase {

	/**
	 * Run the pass over a source pointing at a target with the given value.
	 *
	 * @param mixed $isArray The inverse definition's isArray.
	 * @param mixed $value The source's relation value.
	 *
	 * @return array<string, mixed> The target after the pass.
	 */
	private static function target(mixed $isArray, mixed $value): array {
		$uuid = 'aaaaaaaa-0000-4000-8000-000000000001';
		$objects = [
			['@self' => ['id' => 'src', 'schema' => 1], 'members' => $value],
			['@self' => ['id' => $uuid, 'schema' => 2]],
		];
		$pass = new BulkInverseRelations(
			schemaAnalysis: [1 => ['inverseProperties' => ['members' => ['inversedBy' => 'memberOf', 'isArray' => $isArray]]]],
			isUuid: static fn (string $candidate): bool => str_contains($candidate, '-4000-')
		);
		$pass->apply(objects: $objects);

		return $objects[1];
	}//end target()

	/**
	 * A list property links its list items; a scalar value for it links
	 * nothing; a non-boolean isArray links nothing.
	 *
	 * @return void
	 */
	public function testOnlyAListValueOfAListPropertyIsLinked(): void {
		$uuid = 'aaaaaaaa-0000-4000-8000-000000000001';

		$this->assertSame(['src'], self::target(isArray: true, value: [$uuid])['memberOf']);
		$this->assertArrayNotHasKey('memberOf', self::target(isArray: true, value: $uuid));
		$this->assertArrayNotHasKey('memberOf', self::target(isArray: 'yes', value: [$uuid]));
		$this->assertSame(['src'], self::target(isArray: false, value: $uuid)['memberOf']);
	}//end testOnlyAListValueOfAListPropertyIsLinked()

	/**
	 * A source without an id links nothing, a target outside the batch is
	 * left alone, and a target whose inverse field is not a list is reset to
	 * a list holding the source.
	 *
	 * @return void
	 */
	public function testIdlessSourcesOutsideTargetsAndScalarInverseFields(): void {
		$inside = 'aaaaaaaa-0000-4000-8000-000000000001';
		$outside = 'aaaaaaaa-0000-4000-8000-000000000009';
		$objects = [
			['@self' => ['schema' => 1], 'members' => [$inside]],
			['@self' => ['id' => 'src', 'schema' => 1], 'members' => [$inside, $outside]],
			['@self' => ['id' => $inside, 'schema' => 2], 'memberOf' => 'legacy'],
		];
		$pass = new BulkInverseRelations(
			schemaAnalysis: [1 => ['inverseProperties' => ['members' => ['inversedBy' => 'memberOf', 'isArray' => true]]]],
			isUuid: static fn (string $candidate): bool => str_contains($candidate, '-4000-')
		);

		$pass->apply(objects: $objects);

		$this->assertSame(['src'], $objects[2]['memberOf']);
		$this->assertCount(3, $objects);
		$this->assertArrayNotHasKey('memberOf', $objects[0]);
	}//end testIdlessSourcesOutsideTargetsAndScalarInverseFields()
}//end class
