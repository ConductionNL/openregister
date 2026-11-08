<?php

/**
 * Golden test for the Mapping entity: slug generation, getters and the
 * serialised form, pinned before the slug logic moved out of the entity.
 * Names are ASCII so the result does not depend on the PHP build's
 * transliteration support.
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
use OCA\OpenRegister\Db\Mapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the observable behaviour of Mapping.
 */
class MappingEntityGoldenTest extends TestCase {

	/**
	 * Slug inputs and the slug they give.
	 *
	 * @return array<string, array{string|null, string|null, int|null, string}>
	 */
	public static function slugCases(): array {
		return [
			'stored slug wins' => ['stored', 'Any Name', 3, 'stored'],
			'from name' => [null, '  My Mapping: v2!  ', 3, 'my-mapping-v2'],
			'empty slug is not stored' => ['', 'Name', null, 'name'],
			'zero slug is not stored' => ['0', 'Name', null, 'name'],
			'punctuation only falls back to id' => [null, '!!!', 12, 'mapping-12'],
			'no name falls back to id' => [null, null, 7, 'mapping-7'],
			'blank name falls back to id' => [null, '   ', 8, 'mapping-8'],
		];
	}//end slugCases()

	/**
	 * The slug a mapping reads as.
	 *
	 * @param string|null $slug     The stored slug.
	 * @param string|null $name     The name.
	 * @param int|null    $id       The id.
	 * @param string      $expected The slug.
	 *
	 * @return void
	 */
	#[DataProvider('slugCases')]
	public function testSlug(?string $slug, ?string $name, ?int $id, string $expected): void {
		$mapping = new Mapping();
		$mapping->setSlug($slug);
		$mapping->setName($name);
		if ($id !== null) {
			$mapping->setId($id);
		}

		$this->assertSame($expected, $mapping->getSlug());
	}//end testSlug()

	/**
	 * Without a usable name or id, the slug is random but well-formed.
	 *
	 * @return void
	 */
	public function testSlugWithoutNameOrIdIsRandom(): void {
		$mapping = new Mapping();
		$mapping->setName('???');
		$this->assertMatchesRegularExpression('/^mapping-[0-9a-f]{8}$/', $mapping->getSlug());
	}//end testSlugWithoutNameOrIdIsRandom()

	/**
	 * Field types, getters and the serialised form.
	 *
	 * @return void
	 */
	public function testFieldTypesGettersAndSerialisation(): void {
		$mapping = new Mapping();
		$this->assertSame(
			['id', 'uuid', 'reference', 'version', 'name', 'description', 'mapping', 'unset', 'cast', 'passThrough', 'configurations', 'slug', 'organisation', 'created', 'updated'],
			array_keys($mapping->getFieldTypes())
		);
		$this->assertSame([], $mapping->getMapping());
		$this->assertSame([], $mapping->getUnset());
		$this->assertSame([], $mapping->getCast());
		$this->assertSame([], $mapping->getConfigurations());

		$mapping->setId(4);
		$mapping->setUuid('u');
		$mapping->setName('N');
		$mapping->setDescription('D');
		$mapping->setVersion('1');
		$mapping->setReference('ref');
		$mapping->setMapping(['a' => 'b']);
		$mapping->setUnset(['x']);
		$mapping->setCast(['c' => 'int']);
		$mapping->setPassThrough(true);
		$mapping->setConfigurations([1]);
		$mapping->setOrganisation('org');
		$mapping->setCreated(new DateTime('2026-01-02T03:04:05+00:00'));
		$this->assertSame(
			[
				'id' => 4,
				'uuid' => 'u',
				'name' => 'N',
				'description' => 'D',
				'version' => '1',
				'reference' => 'ref',
				'mapping' => ['a' => 'b'],
				'unset' => ['x'],
				'cast' => ['c' => 'int'],
				'passThrough' => true,
				'configurations' => [1],
				'slug' => 'n',
				'organisation' => 'org',
				'created' => '2026-01-02T03:04:05+00:00',
				'updated' => null,
			],
			$mapping->jsonSerialize()
		);
	}//end testFieldTypesGettersAndSerialisation()
}//end class
