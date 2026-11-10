<?php

/**
 * Golden test for the Register entity: field types, hydrate, serialisation,
 * configuration, magic mapping, languages and configuration ownership, pinned
 * before the entity's logic moved into collaborators next to it.
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

use OCA\OpenRegister\Db\Register\MagicMappingOptions;
use DateTime;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Register;
use PHPUnit\Framework\TestCase;

/**
 * Pins the observable behaviour of Register.
 */
class RegisterEntityGoldenTest extends TestCase {

	/**
	 * The field types, in registration order.
	 *
	 * @return void
	 */
	public function testFieldTypesInOrder(): void {
		$expected = [
			'id' => 'integer',
			'uuid' => 'string',
			'slug' => 'string',
			'title' => 'string',
			'version' => 'string',
			'description' => 'string',
			'schemas' => 'json',
			'source' => 'string',
			'tablePrefix' => 'string',
			'folder' => 'string',
			'updated' => 'datetime',
			'created' => 'datetime',
			'owner' => 'string',
			'application' => 'string',
			'organisation' => 'string',
			'authorization' => 'json',
			'sharedWith' => 'json',
			'groups' => 'json',
			'deleted' => 'datetime',
			'type' => 'string',
			'languages' => 'json',
			'configuration' => 'json',
			'mail' => 'json',
			'contacts' => 'json',
			'notes' => 'json',
			'todos' => 'json',
			'calendar' => 'json',
			'talk' => 'json',
			'deck' => 'json',
		];
		$register = new Register();
		$this->assertSame($expected, $register->getFieldTypes());
		$this->assertSame(
			['schemas', 'authorization', 'sharedWith', 'groups', 'languages', 'configuration', 'mail', 'contacts', 'notes', 'todos', 'calendar', 'talk', 'deck'],
			$register->getJsonFields()
		);
	}//end testFieldTypesInOrder()

	/**
	 * An empty register serialises to its defaults.
	 *
	 * @return void
	 */
	public function testEmptyRegisterSerialises(): void {
		$expected = [
			'id' => null,
			'uuid' => null,
			'slug' => null,
			'title' => null,
			'version' => null,
			'description' => null,
			'schemas' => [],
			'source' => null,
			'tablePrefix' => null,
			'folder' => null,
			'updated' => null,
			'created' => null,
			'owner' => null,
			'application' => null,
			'organisation' => null,
			'authorization' => [],
			'sharedWith' => [],
			'groups' => [],
			'type' => null,
			'languages' => null,
			'configuration' => [],
			'quota' => ['storage' => null, 'bandwidth' => null, 'requests' => null, 'users' => null, 'groups' => null],
			'usage' => ['storage' => 0, 'bandwidth' => 0, 'requests' => 0, 'users' => 0, 'groups' => 0],
			'deleted' => null,
			'_mail' => null,
			'_contacts' => null,
			'_notes' => null,
			'_todos' => null,
			'_calendar' => null,
			'_talk' => null,
			'_deck' => null,
		];
		$this->assertSame($expected, (new Register())->jsonSerialize());
	}//end testEmptyRegisterSerialises()

	/**
	 * A hydrated register serialises every field, in key order.
	 *
	 * @return void
	 */
	public function testHydratedRegisterSerialises(): void {
		$register = $this->fullRegister();
		$register->setId(7);

		$expected = [
			'id' => 7,
			'uuid' => 'u-1',
			'slug' => 'reg',
			'title' => 'Reg',
			'version' => '1.0.0',
			'description' => 'D',
			'schemas' => [0 => 1, 1 => 'two', 3 => 4],
			'source' => 'internal',
			'tablePrefix' => 'tp_',
			'folder' => '12',
			'updated' => '2026-01-02T03:04:05+00:00',
			'created' => '2025-06-07T08:09:10+00:00',
			'owner' => 'alice',
			'application' => 'app',
			'organisation' => 'org-1',
			'authorization' => ['read' => ['group-a']],
			'sharedWith' => ['bob'],
			'groups' => ['g1' => ['x'], 'g2' => ['y']],
			'type' => 'zaak',
			'languages' => ['en', 'nl'],
			'configuration' => ['schemas' => ['3' => ['magicMapping' => true]]],
			'quota' => ['storage' => null, 'bandwidth' => null, 'requests' => null, 'users' => null, 'groups' => null],
			'usage' => ['storage' => 0, 'bandwidth' => 0, 'requests' => 0, 'users' => 0, 'groups' => 2],
			'deleted' => '2026-03-04T05:06:07+00:00',
			'_mail' => ['m'],
			'_contacts' => ['c'],
			'_notes' => ['n'],
			'_todos' => ['t'],
			'_calendar' => ['cal'],
			'_talk' => ['talk'],
			'_deck' => ['deck'],
		];
		$this->assertSame($expected, $register->jsonSerialize());
		$this->assertSame('Reg', (string)$register);
	}//end testHydratedRegisterSerialises()

	/**
	 * Hydrate stores exact keys only, turns an empty json field into null and ignores the rest.
	 *
	 * @return void
	 */
	public function testHydrateKeepsToExactKeys(): void {
		$register = new Register();
		$register->hydrate(
			[
				'title' => 'T',
				'Organisation' => 'wrong-case',
				'groups' => [],
				'languages' => [],
				'unknown' => 'x',
				0 => 'numeric',
				'metadata' => ['a' => 1],
			]
		);

		$this->assertSame('T', $register->getTitle());
		$this->assertNull($register->getOrganisation());
		$this->assertNull($register->getGroups());
		$this->assertNull($register->getLanguages());
		$this->assertNull($register->getSlug());
		$this->assertSame(['title', 'groups'], array_keys($register->getUpdatedFields()));
		$this->assertSame($register, $register->hydrate([]));
	}//end testHydrateKeepsToExactKeys()

	/**
	 * The schemas setter decodes, filters and keeps keys.
	 *
	 * @return void
	 */
	public function testSetSchemasVariants(): void {
		$register = new Register();
		$this->assertSame($register, $register->setSchemas('[1,"a",null,2.5,true,3]'));
		$this->assertSame([0 => 1, 1 => 'a', 5 => 3], $register->getSchemas());
		$register->setSchemas('not json');
		$this->assertSame([], $register->getSchemas());
		$register->setSchemas('"str"');
		$this->assertSame([], $register->getSchemas());
		$register->setSchemas(42);
		$this->assertSame([], $register->getSchemas());
		$register->setSchemas(['k' => 'v', 'n' => [1]]);
		$this->assertSame(['k' => 'v'], $register->getSchemas());
		$this->assertArrayHasKey('schemas', $register->getUpdatedFields());
	}//end testSetSchemasVariants()

	/**
	 * Adding a schema id is idempotent across int and numeric-string entries.
	 *
	 * @return void
	 */
	public function testAddSchemaId(): void {
		$register = new Register();
		$register->setSchemas(['legacy', '5', 6]);
		$this->assertFalse($register->addSchemaId(5));
		$this->assertFalse($register->addSchemaId(6));
		$this->assertTrue($register->addSchemaId(7));
		$this->assertSame(['legacy', '5', 6, 7], $register->getSchemas());
	}//end testAddSchemaId()

	/**
	 * The configuration setter and getter.
	 *
	 * @return void
	 */
	public function testConfigurationVariants(): void {
		$register = new Register();
		$register->setConfiguration('{"a":1}');
		$this->assertSame(['a' => 1], $register->getConfiguration());
		$this->assertSame(['a' => 1], $register->jsonSerialize()['configuration']);
		$register->setConfiguration('{broken');
		$this->assertSame([], $register->getConfiguration());
		$this->assertNull($register->jsonSerialize()['configuration']);
		$register->setConfiguration('5');
		$this->assertNull($register->jsonSerialize()['configuration']);
		$register->setConfiguration(null);
		$this->assertSame([], $register->getConfiguration());
		$this->assertNull($register->jsonSerialize()['configuration']);
		$register->setConfiguration(['b' => 2]);
		$this->assertSame(['b' => 2], $register->getConfiguration());
		$this->assertSame(['configuration' => true], $register->getUpdatedFields());
	}//end testConfigurationVariants()

	/**
	 * Magic mapping in the new per-schema format.
	 *
	 * @return void
	 */
	public function testMagicMappingNewFormat(): void {
		$register = new Register();
		$register->setConfiguration(
			[
				'schemas' => [
					'people' => ['magicMapping' => false, 'autoCreateTable' => true],
					'5' => ['magicMapping' => true],
					7 => ['magicMapping' => true, 'autoCreateTable' => true],
					'cars' => ['magicMapping' => true],
					8 => ['magicMapping' => 'yes'],
				],
			]
		);

		$this->assertTrue($register->isMagicMappingEnabledForSchema(5));
		$this->assertTrue($register->isMagicMappingEnabledForSchema(7));
		$this->assertTrue($register->isMagicMappingEnabledForSchema(99, 'cars'));
		$this->assertTrue($register->isMagicMappingEnabledForSchema(5, 'people'));
		$this->assertFalse($register->isMagicMappingEnabledForSchema(99, 'people'));
		$this->assertFalse($register->isMagicMappingEnabledForSchema(8));
		$this->assertFalse($register->isMagicMappingEnabledForSchema(99));

		$this->assertTrue($register->isAutoCreateTableEnabledForSchema(99, 'people'));
		$this->assertFalse($register->isAutoCreateTableEnabledForSchema(7, 'cars'));
		$this->assertTrue($register->isAutoCreateTableEnabledForSchema(7));
		$this->assertFalse($register->isAutoCreateTableEnabledForSchema(5));
		$this->assertFalse($register->isAutoCreateTableEnabledForSchema(99));
		$this->assertSame([5, 7, 0], $register->getSchemasWithMagicMapping());
	}//end testMagicMappingNewFormat()

	/**
	 * Magic mapping in the legacy list format.
	 *
	 * @return void
	 */
	public function testMagicMappingLegacyFormat(): void {
		$register = new Register();
		$register->setConfiguration(['enableMagicMapping' => true, 'magicMappingSchemas' => ['3', 'cars']]);
		$this->assertTrue($register->isMagicMappingEnabledForSchema(3));
		$this->assertTrue($register->isMagicMappingEnabledForSchema(9, 'cars'));
		$this->assertFalse($register->isMagicMappingEnabledForSchema(9));
		$this->assertTrue($register->isAutoCreateTableEnabledForSchema(3));
		$this->assertFalse($register->isAutoCreateTableEnabledForSchema(9));
		$this->assertSame([], $register->getSchemasWithMagicMapping());

		$register->setConfiguration(['enableMagicMapping' => 'true', 'magicMappingSchemas' => ['3']]);
		$this->assertFalse($register->isMagicMappingEnabledForSchema(3));
		$register->setConfiguration(['enableMagicMapping' => true, 'magicMappingSchemas' => [3]]);
		$this->assertFalse($register->isMagicMappingEnabledForSchema(3));
	}//end testMagicMappingLegacyFormat()

	/**
	 * Enabling and disabling magic mapping write the configuration.
	 *
	 * @return void
	 */
	public function testEnableAndDisableMagicMapping(): void {
		$register = new Register();
		$register->setConfiguration(['other' => 1]);
		$this->assertSame($register, $register->enableMagicMappingForSchema(4));
		$register->enableMagicMappingForSchema(5, new MagicMappingOptions(autoCreateTable: false, comment: 'why'));
		$this->assertSame(
			[
				'other' => 1,
				'schemas' => [
					4 => ['magicMapping' => true, 'autoCreateTable' => true],
					5 => ['magicMapping' => true, 'autoCreateTable' => false, 'comment' => 'why'],
				],
			],
			$register->getConfiguration()
		);
		$this->assertSame($register, $register->disableMagicMappingForSchema(4));
		$register->disableMagicMappingForSchema(77);
		$this->assertSame(['magicMapping' => false, 'autoCreateTable' => true], $register->getConfiguration()['schemas'][4]);
		$this->assertSame([5], $register->getSchemasWithMagicMapping());
		$this->assertFalse(isset($register->getConfiguration()['schemas'][77]));
	}//end testEnableAndDisableMagicMapping()

	/**
	 * Languages default to Dutch.
	 *
	 * @return void
	 */
	public function testLanguages(): void {
		$register = new Register();
		$this->assertSame('nl', $register->getDefaultLanguage());
		$this->assertTrue($register->hasLanguage('nl'));
		$this->assertFalse($register->hasLanguage('en'));
		$register->setLanguages(['en', 'de']);
		$this->assertSame('en', $register->getDefaultLanguage());
		$this->assertTrue($register->hasLanguage('de'));
		$this->assertFalse($register->hasLanguage('nl'));
	}//end testLanguages()

	/**
	 * Configuration ownership matches the id strictly.
	 *
	 * @return void
	 */
	public function testManagedByConfiguration(): void {
		$first = new Configuration();
		$first->setRegisters(['3']);
		$second = new Configuration();
		$second->setRegisters([3]);
		$third = new Configuration();
		$third->setRegisters([3]);

		$register = new Register();
		$this->assertFalse($register->isManagedByConfiguration([$second]));
		$this->assertNull($register->getManagedByConfiguration([$second]));
		$register->setId(3);
		$this->assertFalse($register->isManagedByConfiguration([]));
		$this->assertNull($register->getManagedByConfiguration([]));
		$this->assertFalse($register->isManagedByConfiguration([$first]));
		$this->assertTrue($register->isManagedByConfiguration([$first, $second, $third]));
		$this->assertSame($second, $register->getManagedByConfiguration([$first, $second, $third]));
	}//end testManagedByConfiguration()

	/**
	 * The string form falls back from title to slug to id.
	 *
	 * @return void
	 */
	public function testToString(): void {
		$register = new Register();
		$this->assertSame('Register #unknown', (string)$register);
		$register->setId(4);
		$this->assertSame('Register #4', (string)$register);
		$register->setSlug('s');
		$this->assertSame('s', (string)$register);
		$register->setTitle('');
		$this->assertSame('s', (string)$register);
	}//end testToString()

	/**
	 * A register with every field set through hydrate.
	 *
	 * @return Register
	 */
	private function fullRegister(): Register {
		$register = new Register();
		$register->hydrate(
			[
				'uuid' => 'u-1',
				'slug' => 'reg',
				'title' => 'Reg',
				'version' => '1.0.0',
				'description' => 'D',
				'schemas' => [1, 'two', null, 4],
				'source' => 'internal',
				'tablePrefix' => 'tp_',
				'folder' => '12',
				'updated' => new DateTime('2026-01-02T03:04:05+00:00'),
				'created' => new DateTime('2025-06-07T08:09:10+00:00'),
				'owner' => 'alice',
				'application' => 'app',
				'organisation' => 'org-1',
				'authorization' => ['read' => ['group-a']],
				'sharedWith' => ['bob'],
				'groups' => ['g1' => ['x'], 'g2' => ['y']],
				'deleted' => new DateTime('2026-03-04T05:06:07+00:00'),
				'type' => 'zaak',
				'languages' => ['en', 'nl'],
				'configuration' => '{"schemas":{"3":{"magicMapping":true}}}',
				'mail' => ['m'],
				'contacts' => ['c'],
				'notes' => ['n'],
				'todos' => ['t'],
				'calendar' => ['cal'],
				'talk' => ['talk'],
				'deck' => ['deck'],
			]
		);

		return $register;
	}//end fullRegister()
}//end class
