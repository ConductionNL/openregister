<?php

/**
 * Golden test for the Application entity: field types, quota writes,
 * serialisation and configuration ownership, pinned before its logic moved
 * into collaborators.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use PHPUnit\Framework\TestCase;

/**
 * Pins the observable behaviour of Application.
 */
class ApplicationEntityGoldenTest extends TestCase {

	/**
	 * The field types, in registration order.
	 *
	 * @return void
	 */
	public function testFieldTypesInOrder(): void {
		$this->assertSame(
			[
				'id' => 'integer',
				'uuid' => 'string',
				'name' => 'string',
				'description' => 'string',
				'version' => 'string',
				'organisation' => 'string',
				'configurations' => 'json',
				'registers' => 'json',
				'schemas' => 'json',
				'owner' => 'string',
				'active' => 'boolean',
				'storageQuota' => 'integer',
				'bandwidthQuota' => 'integer',
				'requestQuota' => 'integer',
				'userQuota' => 'integer',
				'groupQuota' => 'integer',
				'groups' => 'json',
				'authorization' => 'json',
				'created' => 'datetime',
				'updated' => 'datetime',
			],
			(new Application())->getFieldTypes()
		);
	}//end testFieldTypesInOrder()

	/**
	 * The quota setter: partial, null, invalid string, negatives and non-numerics.
	 *
	 * @return void
	 */
	public function testSetQuotaVariants(): void {
		$app = new Application();
		$this->assertSame($app, $app->setQuota(['storage' => 100, 'bandwidth' => -5, 'requests' => 'lots', 'users' => '7']));
		$this->assertSame(['storage' => 100, 'bandwidth' => 0, 'requests' => null, 'users' => 7, 'groups' => null], $app->jsonSerialize()['quota']);

		$app->setQuota('{"groups": 3}');
		$this->assertSame(['storage' => 100, 'bandwidth' => 0, 'requests' => null, 'users' => 7, 'groups' => 3], $app->jsonSerialize()['quota']);

		$this->assertSame($app, $app->setQuota('{broken'));
		$app->setQuota('"text"');
		$this->assertSame(3, $app->getGroupQuota());

		$app->setQuota(null);
		$this->assertSame(['storage' => null, 'bandwidth' => null, 'requests' => null, 'users' => null, 'groups' => null], $app->jsonSerialize()['quota']);
		$this->assertSame(['storageQuota', 'bandwidthQuota', 'userQuota', 'groupQuota'], array_keys($app->getUpdatedFields()));
	}//end testSetQuotaVariants()

	/**
	 * Serialisation of an empty and a filled application.
	 *
	 * @return void
	 */
	public function testSerialises(): void {
		$app = new Application();
		$app->setUuid('u');
		$expected = [
			'id' => null,
			'uuid' => 'u',
			'name' => null,
			'description' => null,
			'version' => null,
			'organisation' => null,
			'configurations' => [],
			'registers' => [],
			'schemas' => [],
			'owner' => null,
			'active' => true,
			'groups' => [],
			'quota' => ['storage' => null, 'bandwidth' => null, 'requests' => null, 'users' => null, 'groups' => null],
			'usage' => ['storage' => 0, 'bandwidth' => 0, 'requests' => 0, 'users' => 0, 'groups' => 0],
			'authorization' => ['create' => [], 'read' => [], 'update' => [], 'delete' => []],
			'created' => null,
			'updated' => null,
			'managedByConfiguration' => null,
		];
		$this->assertSame($expected, $app->jsonSerialize());

		$app->hydrate(['name' => 'N', 'groups' => ['a', 'b'], 'active' => '', 'created' => new DateTime('2026-01-01T00:00:00+00:00'), 'authorization' => ['read' => ['x']]]);
		$json = $app->jsonSerialize();
		$this->assertSame('N', $json['name']);
		$this->assertSame(['a', 'b'], $json['groups']);
		$this->assertSame(2, $json['usage']['groups']);
		$this->assertTrue($json['active']);
		$this->assertSame(['read' => ['x']], $json['authorization']);
		$this->assertSame('u', (string)$app);
		$app->setActive(0);
		$this->assertFalse($app->isActive());

		$fresh = new Application();
		$fresh->setOrganisation(null);
		$this->assertSame(['organisation' => true], $fresh->getUpdatedFields());
		$fresh->setOrganisation('org');
		$this->assertSame('org', $fresh->getOrganisation());
	}//end testSerialises()

	/**
	 * Configuration ownership matches the id strictly.
	 *
	 * @return void
	 */
	public function testManagedByConfiguration(): void {
		$loose = new Configuration();
		$loose->setApplications(['2']);
		$strict = new Configuration();
		$strict->setApplications([2]);

		$app = new Application();
		$this->assertNull($app->getManagedByConfiguration([$strict]));
		$this->assertFalse($app->isManagedByConfiguration([$strict]));
		$app->setId(2);
		$this->assertFalse($app->isManagedByConfiguration([]));
		$this->assertNull($app->getManagedByConfiguration([$loose]));
		$this->assertTrue($app->isManagedByConfiguration([$loose, $strict]));
		$this->assertSame($strict, $app->getManagedByConfiguration([$loose, $strict]));
	}//end testManagedByConfiguration()
}//end class
