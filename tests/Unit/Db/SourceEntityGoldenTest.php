<?php

/**
 * Golden test for the Source entity: field types, hydrate, serialisation and
 * configuration ownership, pinned before its logic moved into collaborators.
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
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Source;
use PHPUnit\Framework\TestCase;

/**
 * Pins the observable behaviour of Source.
 */
class SourceEntityGoldenTest extends TestCase {

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
				'title' => 'string',
				'version' => 'string',
				'description' => 'string',
				'databaseUrl' => 'string',
				'type' => 'string',
				'organisation' => 'string',
				'syncEnabled' => 'boolean',
				'syncSchedule' => 'string',
				'syncInterval' => 'integer',
				'lastSyncDate' => 'datetime',
				'lastSyncStatus' => 'string',
				'lastSyncToken' => 'string',
				'authType' => 'string',
				'authConfig' => 'json',
				'mappingId' => 'integer',
				'targetRegister' => 'string',
				'targetSchema' => 'string',
				'conflictStrategy' => 'string',
				'deleteStrategy' => 'string',
				'batchSize' => 'integer',
				'updated' => 'datetime',
				'created' => 'datetime',
			],
			(new Source())->getFieldTypes()
		);
	}//end testFieldTypesInOrder()

	/**
	 * An empty source serialises to its defaults.
	 *
	 * @return void
	 */
	public function testEmptySourceSerialises(): void {
		$expected = array_fill_keys(
			['id', 'uuid', 'title', 'version', 'description', 'databaseUrl', 'type', 'organisation', 'syncEnabled', 'syncSchedule', 'syncInterval', 'lastSyncDate', 'lastSyncStatus', 'lastSyncToken', 'authType'],
			null
		);
		$expected['authConfigured'] = false;
		$expected += array_fill_keys(['mappingId', 'targetRegister', 'targetSchema', 'conflictStrategy', 'deleteStrategy', 'batchSize', 'updated', 'created', 'managedByConfiguration'], null);
		$this->assertSame($expected, (new Source())->jsonSerialize());
		$this->assertSame('Source', (string)(new Source()));
	}//end testEmptySourceSerialises()

	/**
	 * A hydrated source serialises every field and never the credential blob.
	 *
	 * @return void
	 */
	public function testHydratedSourceSerialises(): void {
		$source = new Source();
		$source->hydrate(
			[
				'uuid' => 'u',
				'title' => 'T',
				'version' => '2',
				'description' => 'D',
				'databaseUrl' => 'pgsql://h',
				'type' => 'api',
				'organisation' => 'org',
				'syncEnabled' => true,
				'syncSchedule' => '* * * * *',
				'syncInterval' => 60,
				'lastSyncDate' => new DateTime('2026-02-03T04:05:06+00:00'),
				'lastSyncStatus' => 'ok',
				'lastSyncToken' => 'tok',
				'authType' => 'basic',
				'authConfig' => ['secret' => 'x'],
				'mappingId' => 3,
				'targetRegister' => 'r',
				'targetSchema' => 's',
				'conflictStrategy' => 'skip',
				'deleteStrategy' => 'keep',
				'batchSize' => 50,
				'updated' => new DateTime('2026-01-01T00:00:00+00:00'),
				'created' => new DateTime('2025-01-01T00:00:00+00:00'),
				'unknown' => 'ignored',
			]
		);
		$source->setId(9);

		$this->assertSame(
			[
				'id' => 9,
				'uuid' => 'u',
				'title' => 'T',
				'version' => '2',
				'description' => 'D',
				'databaseUrl' => 'pgsql://h',
				'type' => 'api',
				'organisation' => 'org',
				'syncEnabled' => true,
				'syncSchedule' => '* * * * *',
				'syncInterval' => 60,
				'lastSyncDate' => '2026-02-03T04:05:06+00:00',
				'lastSyncStatus' => 'ok',
				'lastSyncToken' => 'tok',
				'authType' => 'basic',
				'authConfigured' => true,
				'mappingId' => 3,
				'targetRegister' => 'r',
				'targetSchema' => 's',
				'conflictStrategy' => 'skip',
				'deleteStrategy' => 'keep',
				'batchSize' => 50,
				'updated' => '2026-01-01T00:00:00+00:00',
				'created' => '2025-01-01T00:00:00+00:00',
				'managedByConfiguration' => null,
			],
			$source->jsonSerialize()
		);
		$this->assertSame('T', (string)$source);
	}//end testHydratedSourceSerialises()

	/**
	 * An empty json field hydrates to null, and the string form falls back.
	 *
	 * @return void
	 */
	public function testHydrateEmptyJsonAndToString(): void {
		$source = new Source();
		$source->setAuthConfig(['a' => 1]);
		$this->assertSame($source, $source->hydrate(['authConfig' => [], 'metadata' => ['x' => 1]]));
		$this->assertNull($source->getAuthConfig());
		$this->assertFalse($source->jsonSerialize()['authConfigured']);

		$source->setId(4);
		$this->assertSame('Source #4', (string)$source);
		$source->setUuid('');
		$this->assertSame('Source #4', (string)$source);
		$source->setUuid('uu');
		$this->assertSame('uu', (string)$source);
		$source->setTitle('');
		$this->assertSame('uu', (string)$source);
		$fresh = new Source();
		$fresh->setOrganisation(null);
		$this->assertSame(['organisation' => true], $fresh->getUpdatedFields());
		$source->setOrganisation('o');
		$this->assertSame('o', $source->getOrganisation());
		$this->assertArrayHasKey('organisation', $source->getUpdatedFields());
	}//end testHydrateEmptyJsonAndToString()

	/**
	 * Configuration ownership matches the id strictly.
	 *
	 * @return void
	 */
	public function testManagedByConfiguration(): void {
		$loose = new Configuration();
		$loose->setSources(['2']);
		$strict = new Configuration();
		$strict->setSources([2]);

		$source = new Source();
		$this->assertFalse($source->isManagedByConfiguration([$strict]));
		$this->assertNull($source->getManagedByConfiguration([$strict]));
		$source->setId(2);
		$this->assertFalse($source->isManagedByConfiguration([]));
		$this->assertFalse($source->isManagedByConfiguration([$loose]));
		$this->assertNull($source->getManagedByConfiguration([$loose]));
		$this->assertTrue($source->isManagedByConfiguration([$loose, $strict]));
		$this->assertSame($strict, $source->getManagedByConfiguration([$loose, $strict]));
	}//end testManagedByConfiguration()
}//end class
