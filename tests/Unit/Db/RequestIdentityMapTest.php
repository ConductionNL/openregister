<?php

/**
 * The request identity map shared by SchemaMapper and RegisterMapper.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RequestIdentityMap;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\RequestIdentityMap
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class RequestIdentityMapTest extends TestCase {
	private static function schema(int $id, ?string $uuid, ?string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid($uuid);
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	public function testTheKeyIsTheLowerCasedIdentifierAndTheOrganisationFlag(): void {
		$this->assertSame('publicatie:1', RequestIdentityMap::key('Publicatie', true));
		$this->assertSame('42:0', RequestIdentityMap::key(42, false));
	}//end testTheKeyIsTheLowerCasedIdentifierAndTheOrganisationFlag()

	public function testAnEntityIsFoundByEveryIdentifierItWasRememberedUnder(): void {
		$map = new RequestIdentityMap();
		$schema = self::schema(7, 'UUID-7', 'Seven');

		$map->remember($schema, true, ['seven', 7, 'UUID-7', 'Seven']);

		$this->assertSame($schema, $map->get('SEVEN', true));
		$this->assertSame($schema, $map->get('7', true));
		$this->assertSame($schema, $map->get('uuid-7', true));
		$this->assertSame(['seven:1', '7:1', 'uuid-7:1'], $map->keys());
	}//end testAnEntityIsFoundByEveryIdentifierItWasRememberedUnder()

	public function testTheOrganisationScopeIsPartOfTheKey(): void {
		$map = new RequestIdentityMap();
		$map->remember(self::schema(7, null, 'seven'), true, ['seven']);

		$this->assertNull($map->get('seven', false));
	}//end testTheOrganisationScopeIsPartOfTheKey()

	public function testNullAliasesAreSkipped(): void {
		$map = new RequestIdentityMap();
		$map->remember(self::schema(7, null, null), false, [7, 7, null, null]);

		$this->assertSame(['7:0'], $map->keys());
	}//end testNullAliasesAreSkipped()

	public function testForgettingAnIdDropsOnlyItsEntries(): void {
		$map = new RequestIdentityMap();
		$seven = self::schema(7, 'u7', 's7');
		$register = new Register();
		$register->setId(8);
		$map->remember($seven, true, [7, 'u7', 's7']);
		$map->remember($seven, false, [7]);
		$map->remember($register, true, [8]);

		$map->forget(7);

		$this->assertSame(['8:1'], $map->keys());
		$this->assertSame($register, $map->get(8, true));
	}//end testForgettingAnIdDropsOnlyItsEntries()

	public function testClearDropsEverything(): void {
		$map = new RequestIdentityMap();
		$map->remember(self::schema(7, null, null), true, [7]);

		$map->clear();

		$this->assertSame([], $map->keys());
		$this->assertNull($map->get(7, true));
	}//end testClearDropsEverything()
}//end class
