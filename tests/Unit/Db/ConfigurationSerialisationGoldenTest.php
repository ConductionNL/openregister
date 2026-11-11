<?php

/**
 * Characterisation of the Configuration entity: its registered field types and its
 * serialised shape, empty and with every field set, pinned key for key and in order.
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
use OCA\OpenRegister\Db\Configuration;
use PHPUnit\Framework\TestCase;

/**
 * Golden field types and serialisation for Configuration.
 *
 * @covers \OCA\OpenRegister\Db\Configuration
 */
final class ConfigurationSerialisationGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'title' => 'string',
		'description' => 'string',
		'type' => 'string',
		'app' => 'string',
		'version' => 'string',
		'sourceType' => 'string',
		'sourceUrl' => 'string',
		'localVersion' => 'string',
		'remoteVersion' => 'string',
		'lastChecked' => 'datetime',
		'autoUpdate' => 'boolean',
		'notificationGroups' => 'json',
		'githubRepo' => 'string',
		'githubBranch' => 'string',
		'githubPath' => 'string',
		'isLocal' => 'boolean',
		'syncEnabled' => 'boolean',
		'syncInterval' => 'integer',
		'lastSyncDate' => 'datetime',
		'syncStatus' => 'string',
		'openregister' => 'string',
		'registers' => 'json',
		'schemas' => 'json',
		'objects' => 'json',
		'views' => 'json',
		'agents' => 'json',
		'sources' => 'json',
		'mappings' => 'json',
		'applications' => 'json',
		'organisation' => 'string',
		'owner' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];

	/**
	 * The serialised shape of a fresh entity.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_JSON = [
		'id' => null,
		'uuid' => null,
		'title' => null,
		'description' => null,
		'type' => null,
		'app' => null,
		'application' => null,
		'version' => null,
		'sourceType' => null,
		'sourceUrl' => null,
		'localVersion' => null,
		'remoteVersion' => null,
		'lastChecked' => null,
		'autoUpdate' => false,
		'notificationGroups' => [],
		'githubRepo' => null,
		'githubBranch' => null,
		'githubPath' => null,
		'isLocal' => true,
		'syncEnabled' => false,
		'syncInterval' => 24,
		'lastSyncDate' => null,
		'syncStatus' => 'never',
		'openregister' => null,
		'organisation' => null,
		'owner' => null,
		'registers' => [],
		'schemas' => [],
		'objects' => [],
		'views' => [],
		'agents' => [],
		'sources' => [],
		'mappings' => [],
		'applications' => [],
		'created' => null,
		'updated' => null,
	];

	/**
	 * The serialised shape with every field set.
	 *
	 * @var array<string, mixed>
	 */
	private const POPULATED_JSON = [
		'id' => 2,
		'uuid' => 'uuid-v',
		'title' => 'title-v',
		'description' => 'description-v',
		'type' => 'type-v',
		'app' => 'app-v',
		'application' => 'app-v',
		'version' => 'version-v',
		'sourceType' => 'sourceType-v',
		'sourceUrl' => 'sourceUrl-v',
		'localVersion' => 'localVersion-v',
		'remoteVersion' => 'remoteVersion-v',
		'lastChecked' => '2026-01-02T03:04:05+00:00',
		'autoUpdate' => true,
		'notificationGroups' => [
			'k' => 'notificationGroups',
		],
		'githubRepo' => 'githubRepo-v',
		'githubBranch' => 'githubBranch-v',
		'githubPath' => 'githubPath-v',
		'isLocal' => true,
		'syncEnabled' => true,
		'syncInterval' => 12,
		'lastSyncDate' => '2026-01-02T03:04:05+00:00',
		'syncStatus' => 'syncStatus-v',
		'openregister' => 'openregister-v',
		'organisation' => 'organisation-v',
		'owner' => 'owner-v',
		'registers' => [
			'k' => 'registers',
		],
		'schemas' => [
			'k' => 'schemas',
		],
		'objects' => [
			'k' => 'objects',
		],
		'views' => [
			'k' => 'views',
		],
		'agents' => [
			'k' => 'agents',
		],
		'sources' => [
			'k' => 'sources',
		],
		'mappings' => [
			'k' => 'mappings',
		],
		'applications' => [
			'k' => 'applications',
		],
		'created' => '2026-01-02T03:04:05+00:00',
		'updated' => '2026-01-02T03:04:05+00:00',
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new Configuration())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * A fresh entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testEmptyEntitySerialises(): void {
		$this->assertSame(self::EMPTY_JSON, (new Configuration())->jsonSerialize());
	}//end testEmptyEntitySerialises()

	/**
	 * A fully set entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testPopulatedEntitySerialises(): void {
		$this->assertSame(self::POPULATED_JSON, $this->populated(entity: new Configuration())->jsonSerialize());
	}//end testPopulatedEntitySerialises()

	/**
	 * Set every registered field to a value derived from its name and type.
	 *
	 * @param Configuration $entity The entity to fill.
	 *
	 * @return Configuration The filled entity.
	 */
	private function populated(Configuration $entity): Configuration {
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
