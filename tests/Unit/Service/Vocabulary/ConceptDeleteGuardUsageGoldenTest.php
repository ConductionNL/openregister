<?php

/**
 * Golden test pinning what ConceptDeleteGuard::usage() counts and refuses.
 *
 * Pins, for one fleet of schemas, which concepts the guard lets through
 * (count 0, so the delete is allowed) and which it refuses (count above 0),
 * across both storage forms, a scheme mismatch, a blank notation and an
 * unreadable table. Written on the code before the usage scan took one
 * value object, so the refactor can be checked against it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The allowed and refused outcomes of the concept delete guard, pinned.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedNarrowing
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedValueRules
 * @uses \OCA\OpenRegister\Service\Vocabulary\ConceptHolding
 */
class ConceptDeleteGuardUsageGoldenTest extends TestCase {

	private const SCHEME = 'urn:scheme';

	/**
	 * Holder counts per `schema.property=value`; anything absent counts zero.
	 *
	 * @var array<string,int>
	 */
	private const COUNTS = [
		'zaak.resultaat=urn:held' => 3,
		'besluit.soort=H1' => 2,
		'dossier.code=urn:held' => 4,
	];

	/**
	 * Every case's usage, and whether the delete would be allowed.
	 *
	 * @return void
	 */
	public function testUsageOutcomesArePinned(): void {
		$guard = $this->guard();
		$cases = [
			'held by uri and notation' => ['uri' => 'urn:held', 'notation' => 'H1'],
			'held by uri only' => ['uri' => 'urn:held', 'notation' => ''],
			'held by notation only' => ['uri' => 'urn:free', 'notation' => 'H1'],
			'held by nothing' => ['uri' => 'urn:free', 'notation' => 'F1'],
			'no identity' => ['uri' => '', 'notation' => ''],
		];

		$actual = [];
		foreach ($cases as $name => $concept) {
			$usage = $guard->usage(concept: $concept, schemeUri: self::SCHEME);
			$actual[$name] = $usage + ['allowed' => ($usage['count'] === 0)];
		}

		$this->assertSame(self::expected(), $actual);
	}//end testUsageOutcomesArePinned()

	/**
	 * The pinned outcomes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function expected(): array {
		$zaak = ['schema' => 'zaak', 'property' => 'resultaat', 'count' => 3];
		$besluit = ['schema' => 'besluit', 'property' => 'soort', 'count' => 2];
		$dossier = ['schema' => 'dossier', 'property' => 'code', 'count' => 4];
		$free = ['count' => 0, 'holders' => [], 'allowed' => true];

		return [
			'held by uri and notation' => ['count' => 9, 'holders' => [$zaak, $besluit, $dossier], 'allowed' => false],
			'held by uri only' => ['count' => 7, 'holders' => [$zaak, $dossier], 'allowed' => false],
			'held by notation only' => ['count' => 2, 'holders' => [$besluit], 'allowed' => false],
			'held by nothing' => $free,
			'no identity' => $free,
		];
	}//end expected()

	/**
	 * The guard over the fleet, counting from COUNTS.
	 *
	 * @return ConceptDeleteGuard
	 */
	private function guard(): ConceptDeleteGuard {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn($this->fleet());

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('countAll')->willReturnCallback(
			static function (?array $filter, ?Schema $schema): int {
				if ($schema->getSlug() === 'broken') {
					throw new RuntimeException('table unreadable');
				}

				$key = $schema->getSlug().'.'.array_key_first($filter).'='.reset($filter);
				return (self::COUNTS[$key] ?? 0);
			}
		);

		return new ConceptDeleteGuard(
			lifecycle: new ConceptLifecycle(),
			tables: new \OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables(schemas: $schemas, declarations: new CodedPropertyDeclarationFactory(), objects: $objects)
		);
	}//end guard()

	/**
	 * The schemas: uri-stored, notation-stored, another scheme, an unreadable table, an uncoded one.
	 *
	 * @return array<int,Schema>
	 */
	private function fleet(): array {
		return [
			self::schema(slug: 'zaak', properties: ['resultaat' => self::coded(scheme: self::SCHEME, store: 'uri')]),
			self::schema(slug: 'besluit', properties: ['soort' => self::coded(scheme: self::SCHEME, store: 'notation')]),
			self::schema(slug: 'elders', properties: ['code' => self::coded(scheme: 'urn:other', store: 'uri')]),
			self::schema(slug: 'broken', properties: ['code' => self::coded(scheme: self::SCHEME, store: 'uri')]),
			self::schema(slug: 'plain', properties: ['naam' => ['type' => 'string']]),
			self::schema(slug: 'dossier', properties: ['code' => self::coded(scheme: self::SCHEME, store: 'uri')]),
		];
	}//end fleet()

	/**
	 * A coded property definition.
	 *
	 * @param string $scheme The scheme uri.
	 * @param string $store  The storage form.
	 *
	 * @return array<string,mixed>
	 */
	private static function coded(string $scheme, string $store): array {
		return ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => ['scheme' => $scheme, 'store' => $store]];
	}//end coded()

	/**
	 * A schema with a slug and properties.
	 *
	 * @param string              $slug       The slug.
	 * @param array<string,mixed> $properties The properties.
	 *
	 * @return Schema
	 */
	private static function schema(string $slug, array $properties): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		return $schema;
	}//end schema()
}//end class
