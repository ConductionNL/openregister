<?php

/**
 * Characterisation of the View entity: its registered field types and its
 * serialised shape, empty and with every field set, pinned key for key and in order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Db;

use DateTime;
use OCA\OpenRegister\Search\Db\View;
use PHPUnit\Framework\TestCase;

/**
 * Golden field types and serialisation for View.
 *
 * @covers \OCA\OpenRegister\Search\Db\View
 */
final class ViewSerialisationGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'organisation' => 'string',
		'isPublic' => 'boolean',
		'isDefault' => 'boolean',
		'query' => 'json',
		'presentation' => 'json',
		'sharedWith' => 'json',
		'favoredBy' => 'json',
		'created' => 'datetime',
		'updated' => 'datetime',
		'alert' => 'json',
		'alertState' => 'json',
		'alertEvaluatedAt' => 'datetime',
	];

	/**
	 * The serialised shape of a fresh entity.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_JSON = [
		'id' => null,
		'uuid' => null,
		'name' => null,
		'description' => null,
		'owner' => null,
		'organisation' => null,
		'isPublic' => false,
		'isDefault' => false,
		'query' => [],
		'presentation' => [
			'viewType' => 'table',
		],
		'alert' => null,
		'alertState' => null,
		'sharedWith' => [],
		'@self' => [
			'access' => null,
		],
		'favoredBy' => [],
		'quota' => [
			'storage' => null,
			'bandwidth' => null,
			'requests' => null,
			'users' => null,
			'groups' => null,
		],
		'usage' => [
			'storage' => 0,
			'bandwidth' => 0,
			'requests' => 0,
			'users' => 0,
			'groups' => 0,
		],
		'created' => null,
		'updated' => null,
		'managedByConfiguration' => null,
	];

	/**
	 * The serialised shape with every field set.
	 *
	 * @var array<string, mixed>
	 */
	private const POPULATED_JSON = [
		'id' => 2,
		'uuid' => null,
		'name' => null,
		'description' => null,
		'owner' => null,
		'organisation' => 'organisation-v',
		'isPublic' => true,
		'isDefault' => true,
		'query' => [
			'k' => 'query',
		],
		'presentation' => [
			'k' => 'presentation',
			'viewType' => 'table',
		],
		'alert' => [
			'k' => 'alert',
		],
		'alertState' => [
			'k' => 'alertState',
		],
		'sharedWith' => [
			'k' => 'sharedWith',
		],
		'@self' => [
			'access' => null,
		],
		'favoredBy' => [
			'k' => 'favoredBy',
		],
		'quota' => [
			'storage' => null,
			'bandwidth' => null,
			'requests' => null,
			'users' => null,
			'groups' => null,
		],
		'usage' => [
			'storage' => 0,
			'bandwidth' => 0,
			'requests' => 0,
			'users' => 1,
			'groups' => 0,
		],
		'created' => '2026-01-02T03:04:05+00:00',
		'updated' => '2026-01-02T03:04:05+00:00',
		'managedByConfiguration' => null,
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new View())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * A fresh entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testEmptyEntitySerialises(): void {
		$this->assertSame(self::EMPTY_JSON, (new View())->jsonSerialize());
	}//end testEmptyEntitySerialises()

	/**
	 * A fully set entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testPopulatedEntitySerialises(): void {
		$this->assertSame(self::POPULATED_JSON, $this->populated(entity: new View())->jsonSerialize());
	}//end testPopulatedEntitySerialises()

	/**
	 * Set every registered field to a value derived from its name and type.
	 *
	 * @param View $entity The entity to fill.
	 *
	 * @return View The filled entity.
	 */
	private function populated(View $entity): View {
		foreach ($entity->getFieldTypes() as $field => $type) {
			$entity->{'set'.ucfirst($field)}($this->valueFor(field: $field, type: $type));
		}

		return $entity;
	}//end populated()

	/**
	 * A deterministic value for one field.
	 *
	 * @param string $field The field name.
	 * @param string $type  The registered entity type.
	 *
	 * @return mixed The value.
	 */
	private function valueFor(string $field, string $type): mixed {
		return match ($type) {
			'integer', 'int' => strlen($field),
			'boolean', 'bool' => true,
			'json' => ['k' => $field],
			'datetime' => new DateTime('2026-01-02T03:04:05+00:00'),
			'float', 'double' => 1.5,
			default => $field.'-v',
		};
	}//end valueFor()
}//end class
