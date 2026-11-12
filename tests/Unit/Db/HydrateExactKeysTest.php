<?php

/**
 * Schema and Register store a hydrate key only under its exact property name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema
 * @covers \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Register\ExactKeyHydration
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema\TopLevelAnnotationFold
 * @uses \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses \OCA\OpenRegister\Db\Schema\AuthorizationControlKeys
 * @uses \OCA\OpenRegister\Db\Schema\AuthorizationEntry
 * @uses \OCA\OpenRegister\Db\Schema\AuthorizationRulesValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\DeclaredActions
 */
class HydrateExactKeysTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function otherSpellings(): array {
		return [
			'Authorization' => ['Authorization', 'getAuthorization'],
			'Organisation'  => ['Organisation', 'getOrganisation'],
			'Owner'         => ['Owner', 'getOwner'],
			'Slug'          => ['Slug', 'getSlug'],
			'SLUG'          => ['SLUG', 'getSlug'],
			'sLUG'          => ['sLUG', 'getSlug'],
			'Configuration' => ['Configuration', 'getConfiguration'],
		];
	}//end otherSpellings()

	/**
	 * The value a getter should still answer with after another spelling was sent.
	 *
	 * @param string $key The spelling that was sent.
	 *
	 * @return mixed
	 */
	private static function sentValue(string $key): mixed {
		if (in_array($key, ['Authorization', 'Configuration'], true) === true) {
			return ['read' => ['public']];
		}

		return 'sent-value';
	}//end sentValue()

	#[DataProvider('otherSpellings')]
	public function testASchemaStoresNothingUnderAnotherSpelling(string $key, string $getter): void {
		$schema = new Schema();
		$before = $schema->$getter();

		$schema->hydrate([$key => self::sentValue(key: $key)]);

		self::assertSame($before, $schema->$getter());
	}//end testASchemaStoresNothingUnderAnotherSpelling()

	#[DataProvider('otherSpellings')]
	public function testARegisterStoresNothingUnderAnotherSpelling(string $key, string $getter): void {
		$register = new Register();
		$before = $register->$getter();

		$register->hydrate([$key => self::sentValue(key: $key)]);

		self::assertSame($before, $register->$getter());
	}//end testARegisterStoresNothingUnderAnotherSpelling()

	public function testASchemaKeepsAPendingDraftAgainstEverySpelling(): void {
		$schema = new Schema();

		$schema->hydrate(['Draft' => ['title' => 'x'], 'draft' => ['title' => 'y']]);

		self::assertNull($schema->getDraft());
	}//end testASchemaKeepsAPendingDraftAgainstEverySpelling()

	public function testASchemaStoresItsExactKeys(): void {
		$schema = new Schema();

		$schema->hydrate(
			[
				'title' => 'A title',
				'slug' => 'a-slug',
				'organisation' => 'org',
				'owner' => 'alice',
				'authorization' => '{"read":["hr"]}',
				'required' => ['name'],
				'hardValidation' => false,
				'x-schema-org' => 'schema:Thing',
				'unknown' => 'ignored',
				5 => 'a numeric key',
			]
		);

		self::assertSame('A title', $schema->getTitle());
		self::assertSame('a-slug', $schema->getSlug());
		self::assertSame('org', $schema->getOrganisation());
		self::assertSame('alice', $schema->getOwner());
		self::assertSame(['read' => ['hr']], $schema->getAuthorization());
		self::assertSame(['name'], $schema->getRequired());
		self::assertFalse($schema->getHardValidation());
		self::assertSame('schema:Thing', $schema->getConfiguration()['x-schema-org']);
	}//end testASchemaStoresItsExactKeys()

	public function testARegisterStoresItsExactKeys(): void {
		$register = new Register();

		$register->hydrate(['title' => 'A title', 'organisation' => 'org', 'owner' => 'alice', 'schemas' => [1, 2], 'authorization' => ['read' => ['hr']], 5 => 'a numeric key']);

		self::assertSame('A title', $register->getTitle());
		self::assertSame('org', $register->getOrganisation());
		self::assertSame('alice', $register->getOwner());
		self::assertSame([1, 2], $register->getSchemas());
		self::assertSame(['read' => ['hr']], $register->getAuthorization());
	}//end testARegisterStoresItsExactKeys()

	public function testEveryDeclaredSetterStillHasAProperty(): void {
		foreach ([Schema::class, Register::class] as $class) {
			foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() === $class && str_starts_with($method->getName(), 'set') === true) {
					self::assertTrue(property_exists($class, lcfirst(substr($method->getName(), 3))), $method->getName());
				}
			}
		}
	}//end testEveryDeclaredSetterStillHasAProperty()
}//end class
