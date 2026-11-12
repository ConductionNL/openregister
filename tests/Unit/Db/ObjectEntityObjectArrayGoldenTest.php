<?php

/**
 * Characterisation of ObjectEntity's field types and getObjectArray(): the
 * base metadata, the transient render fields, the unread badges and the
 * '@self' overrides, pinned key for key and in order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden field types and object array for ObjectEntity.
 *
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 */
final class ObjectEntityObjectArrayGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'slug' => 'string',
		'uri' => 'string',
		'version' => 'string',
		'register' => 'string',
		'schema' => 'string',
		'object' => 'json',
		'files' => 'json',
		'relations' => 'json',
		'locked' => 'json',
		'owner' => 'string',
		'authorization' => 'json',
		'folder' => 'string',
		'application' => 'string',
		'organisation' => 'string',
		'validation' => 'json',
		'quality' => 'json',
		'deleted' => 'json',
		'archived' => 'json',
		'frozen' => 'json',
		'status' => 'string',
		'geo' => 'json',
		'retention' => 'json',
		'tmlo' => 'json',
		'mail' => 'json',
		'contacts' => 'json',
		'notes' => 'json',
		'todos' => 'json',
		'calendar' => 'json',
		'talk' => 'json',
		'deck' => 'json',
		'size' => 'string',
		'schemaVersion' => 'string',
		'name' => 'string',
		'description' => 'string',
		'summary' => 'string',
		'image' => 'string',
		'updated' => 'datetime',
		'lastUserActivity' => 'datetime',
		'created' => 'datetime',
		'groups' => 'json',
		'expires' => 'datetime',
	];

	/**
	 * The object array of a fresh entity.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_ARRAY = [
		'id' => null,
		'slug' => null,
		'name' => null,
		'description' => null,
		'summary' => null,
		'image' => null,
		'uri' => null,
		'version' => null,
		'register' => null,
		'schema' => null,
		'schemaVersion' => null,
		'files' => [],
		'relations' => [],
		'locked' => null,
		'owner' => null,
		'organisation' => null,
		'groups' => [],
		'authorization' => [],
		'folder' => null,
		'application' => null,
		'validation' => [],
		'quality' => [],
		'geo' => [],
		'retention' => [],
		'tmlo' => [],
		'size' => null,
		'updated' => null,
		'lastUserActivity' => null,
		'created' => null,
		'deleted' => [],
		'archived' => [],
		'frozen' => [],
		'status' => null,
		'source' => null,
		'mail' => null,
		'contacts' => null,
		'notes' => null,
		'todos' => null,
		'calendar' => null,
		'talk' => null,
		'deck' => null,
	];

	/**
	 * The object array with every stored field set.
	 *
	 * @var array<string, mixed>
	 */
	private const POPULATED_ARRAY = [
		'id' => 'uuid-v',
		'slug' => 'slug-v',
		'name' => 'name-v',
		'description' => 'description-v',
		'summary' => 'summary-v',
		'image' => 'image-v',
		'uri' => 'uri-v',
		'version' => 'version-v',
		'register' => 'register-v',
		'schema' => 'schema-v',
		'schemaVersion' => 'schemaVersion-v',
		'files' => [
			'k' => 'files',
		],
		'relations' => [
			'k' => 'relations',
		],
		'locked' => [
			'k' => 'locked',
		],
		'owner' => 'owner-v',
		'organisation' => 'organisation-v',
		'groups' => [
			'k' => 'groups',
		],
		'authorization' => [
			'k' => 'authorization',
		],
		'folder' => 'folder-v',
		'application' => 'application-v',
		'validation' => [
			'k' => 'validation',
		],
		'quality' => [
			'k' => 'quality',
		],
		'geo' => [
			'k' => 'geo',
		],
		'retention' => [
			'k' => 'retention',
		],
		'tmlo' => [
			'k' => 'tmlo',
		],
		'size' => 'size-v',
		'updated' => '2026-01-02T03:04:05+00:00',
		'lastUserActivity' => '2026-01-02T03:04:05+00:00',
		'created' => '2026-01-02T03:04:05+00:00',
		'deleted' => [
			'k' => 'deleted',
		],
		'archived' => [
			'k' => 'archived',
		],
		'frozen' => [
			'k' => 'frozen',
		],
		'status' => 'status-v',
		'source' => null,
		'mail' => [
			'k' => 'mail',
		],
		'contacts' => [
			'k' => 'contacts',
		],
		'notes' => [
			'k' => 'notes',
		],
		'todos' => [
			'k' => 'todos',
		],
		'calendar' => [
			'k' => 'calendar',
		],
		'talk' => [
			'k' => 'talk',
		],
		'deck' => [
			'k' => 'deck',
		],
	];

	/**
	 * The object array with every stored and transient field set and every
	 * '@self' override applied.
	 *
	 * @var array<string, mixed>
	 */
	private const RENDERED_ARRAY = [
		'id' => 'uuid-v',
		'slug' => 'slug-v',
		'name' => 'name-v',
		'description' => 'description-v',
		'summary' => 'summary-v',
		'image' => 'image-v',
		'uri' => 'uri-v',
		'version' => 'version-v',
		'register' => [
			'id' => 1,
		],
		'schema' => [
			'id' => 2,
		],
		'schemaVersion' => 'schemaVersion-v',
		'files' => [
			'k' => 'files',
		],
		'relations' => [
			'k' => 'relations',
		],
		'locked' => [
			'k' => 'locked',
		],
		'owner' => [
			'uid' => 'o',
		],
		'organisation' => 'org-x',
		'groups' => [
			'k' => 'groups',
		],
		'authorization' => [
			'k' => 'authorization',
		],
		'folder' => 'folder-v',
		'application' => [
			'id' => 3,
		],
		'validation' => [
			'k' => 'validation',
		],
		'quality' => [
			'k' => 'quality',
		],
		'geo' => [
			'k' => 'geo',
		],
		'retention' => [
			'k' => 'retention',
		],
		'tmlo' => [
			'k' => 'tmlo',
		],
		'size' => 'size-v',
		'updated' => '2026-01-02T03:04:05+00:00',
		'lastUserActivity' => '2026-01-02T03:04:05+00:00',
		'created' => '2026-01-02T03:04:05+00:00',
		'deleted' => [
			'k' => 'deleted',
		],
		'archived' => [
			'k' => 'archived',
		],
		'frozen' => [
			'k' => 'frozen',
		],
		'status' => 'status-v',
		'source' => null,
		'mail' => [
			'k' => 'mail',
		],
		'contacts' => [
			'k' => 'contacts',
		],
		'notes' => [
			'k' => 'notes',
		],
		'todos' => [
			'k' => 'todos',
		],
		'calendar' => [
			'k' => 'calendar',
		],
		'talk' => [
			'k' => 'talk',
		],
		'deck' => [
			'k' => 'deck',
		],
		'relevance' => 0.5,
		'urn' => 'urn:x',
		'translationCompleteness' => [
			'nl' => 1,
		],
		'_retention' => [
			'r' => 1,
		],
		'_clocks' => [
			'c' => 1,
		],
		'registry' => [
			's' => 1,
		],
		'fieldRules' => [
			'f' => 1,
		],
		'watching' => true,
		'watcherCount' => 3,
		'unread' => false,
		'watchNotify' => true,
		'favourite' => true,
		'viewedAt' => '2026-01-02',
		'can' => [
			'edit' => true,
		],
		'unreadCounts' => [
			'notes' => 2,
		],
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new ObjectEntity())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * A fresh entity: name falls back to the uuid, description to the id, no transient keys.
	 *
	 * @return void
	 */
	public function testEmptyEntity(): void {
		$this->assertSame(self::EMPTY_ARRAY, (new ObjectEntity())->getObjectArray());
	}//end testEmptyEntity()

	/**
	 * Every stored field set, no transient field and no '@self'.
	 *
	 * @return void
	 */
	public function testPopulatedEntity(): void {
		$this->assertSame(self::POPULATED_ARRAY, $this->populated()->getObjectArray());
	}//end testPopulatedEntity()

	/**
	 * Every transient field set and every array-valued '@self' override applied.
	 *
	 * @return void
	 */
	public function testRenderedEntityWithSelfOverrides(): void {
		$object = [
			'@self' => [
				'register' => ['id' => 1],
				'schema' => ['id' => 2],
				'owner' => ['uid' => 'o'],
				'organisation' => 'org-x',
				'application' => ['id' => 3],
			],
		];

		$this->assertSame(self::RENDERED_ARRAY, $this->rendered(unreadCounts: ['notes' => 2])->getObjectArray($object));
	}//end testRenderedEntityWithSelfOverrides()

	/**
	 * Scalar '@self' values, a null organisation and a non-array '@self' override nothing.
	 *
	 * @return void
	 */
	public function testNonArraySelfValuesOverrideNothing(): void {
		$scalars = ['@self' => ['register' => 'r', 'schema' => 's', 'owner' => 'o', 'organisation' => null, 'application' => 'a']];

		$this->assertSame(self::POPULATED_ARRAY, $this->populated()->getObjectArray($scalars));
		$this->assertSame(self::POPULATED_ARRAY, $this->populated()->getObjectArray(['@self' => 'not-an-array']));
		$this->assertSame(self::POPULATED_ARRAY, $this->populated()->getObjectArray(['@self' => null]));
	}//end testNonArraySelfValuesOverrideNothing()

	/**
	 * An empty badge map is omitted, like a missing one.
	 *
	 * @return void
	 */
	public function testEmptyUnreadCountsAreOmitted(): void {
		$expected = self::RENDERED_ARRAY;
		unset($expected['unreadCounts']);
		$expected = array_merge($expected, ['register' => 'register-v', 'schema' => 'schema-v', 'owner' => 'owner-v', 'organisation' => 'organisation-v', 'application' => 'application-v']);

		$this->assertSame($expected, $this->rendered(unreadCounts: [])->getObjectArray());
	}//end testEmptyUnreadCountsAreOmitted()

	/**
	 * An entity with every stored field set to a value derived from its name and type.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function populated(): ObjectEntity {
		$entity = new ObjectEntity();
		foreach ($entity->getFieldTypes() as $field => $type) {
			$value = match ($type) {
				'integer' => strlen($field),
				'json' => ['k' => $field],
				'datetime' => new DateTime('2026-01-02T03:04:05+00:00'),
				default => $field.'-v',
			};
			$entity->{'set'.ucfirst($field)}($value);
		}

		return $entity;
	}//end populated()

	/**
	 * A populated entity with every transient render field set.
	 *
	 * @param array $unreadCounts The tab badges to set.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function rendered(array $unreadCounts): ObjectEntity {
		$entity = $this->populated();
		$values = [
			'relevance' => 0.5,
			'urn' => 'urn:x',
			'translationCompleteness' => ['nl' => 1],
			'archivalRetention' => ['r' => 1],
			'retentionClocks' => ['c' => 1],
			'registryState' => ['s' => 1],
			'fieldRules' => ['f' => 1],
			'watching' => true,
			'watcherCount' => 3,
			'unread' => false,
			'unreadCounts' => $unreadCounts,
			'watchNotify' => true,
			'viewedAt' => '2026-01-02',
			'can' => ['edit' => true],
		];
		$reflection = new ReflectionClass(ObjectEntity::class);
		foreach ($values as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($entity, $value);
		}

		return $entity;
	}//end rendered()
}//end class
