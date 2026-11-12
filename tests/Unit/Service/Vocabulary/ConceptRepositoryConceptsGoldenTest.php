<?php

/**
 * Golden test pinning how ConceptRepository reads and resolves a scheme's concepts.
 *
 * Pins the query the concept read sends to the object layer (the scheme
 * filter, the vocabulary register and concept schema scope, the row cap and
 * the unscoped guard flags), which entities become concepts, and how a stored
 * value resolves in either storage form. Written before the read and resolve
 * signatures changed.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedValueRules;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\TestCase;

/**
 * The concept read and the stored-value resolve of the concept repository, pinned.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptRepository
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedNarrowing
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedValueRules
 */
final class ConceptRepositoryConceptsGoldenTest extends TestCase {

	private const SCHEME = 'https://x/s';

	/**
	 * Every searchObjects call, as [query, _rbac, _multitenancy].
	 *
	 * @var list<array{0:array<string,mixed>,1:mixed,2:mixed}>
	 */
	private array $calls = [];

	/**
	 * The concept read sends one scoped, capped, unguarded query and keeps only uri-carrying entities.
	 *
	 * @return void
	 */
	public function testConceptsOfReadsOnceAndKeysByUri(): void {
		$repository = $this->repository();

		$expected = [
			'urn:a' => ['id' => 'c-1', 'uri' => 'urn:a', 'notation' => 'A', '@uuid' => 'c-1'],
			'urn:b' => ['id' => 'c-2', 'uri' => ' urn:b ', '@uuid' => 'c-2'],
		];
		$this->assertSame($expected, $repository->conceptsOf(schemeUri: self::SCHEME));
		$this->assertSame($expected, $repository->conceptsOf(schemeUri: self::SCHEME));
		$this->assertSame(
			[
				[['uri' => self::SCHEME, '@self' => ['register' => 7, 'schema' => 8], '_limit' => 1], false, false],
				[['inScheme' => 's-1', '@self' => ['register' => 7, 'schema' => 9], '_limit' => ConceptRepository::MAX_SCHEME_CONCEPTS], false, false],
			],
			$this->calls
		);
	}//end testConceptsOfReadsOnceAndKeysByUri()

	/**
	 * A stored value resolves by uri or by notation; anything else answers null.
	 *
	 * @return void
	 */
	public function testResolveInEitherStorageForm(): void {
		$repository = $this->repository();
		$a = ['id' => 'c-1', 'uri' => 'urn:a', 'notation' => 'A', '@uuid' => 'c-1'];

		$this->assertSame($a, $repository->resolve(value: 'urn:a', declaration: new CodedPropertyDeclaration(scheme: self::SCHEME)));
		$this->assertSame($a, $repository->resolve(value: 'urn:a', declaration: self::declaration(store: 'uri')));
		$this->assertNull($repository->resolve(value: 'A', declaration: self::declaration(store: 'uri')));
		$this->assertSame($a, $repository->resolve(value: 'A', declaration: self::declaration(store: 'notation')));
		$this->assertNull($repository->resolve(value: 'urn:a', declaration: self::declaration(store: 'notation')));
		$this->assertNull($repository->resolve(value: 'urn:none', declaration: new CodedPropertyDeclaration(scheme: self::SCHEME)));
	}//end testResolveInEitherStorageForm()

	/**
	 * A declaration on the scheme with the given storage form.
	 *
	 * @param string $store The storage form.
	 *
	 * @return CodedPropertyDeclaration
	 */
	private static function declaration(string $store): CodedPropertyDeclaration {
		return new CodedPropertyDeclaration(scheme: self::SCHEME, rules: new CodedValueRules(store: $store));
	}//end declaration()

	/**
	 * A repository over register 7 (scheme schema 8, concept schema 9) holding one scheme.
	 *
	 * @return ConceptRepository
	 */
	private function repository(): ConceptRepository {
		$register = new Register();
		$register->setId(7);
		$scheme = new Schema();
		$scheme->setId(8);
		$concept = new Schema();
		$concept->setId(9);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnOnConsecutiveCalls($scheme, $concept);

		$results = [
			[self::entity(uuid: 's-1', data: ['uri' => self::SCHEME])],
			[
				self::entity(uuid: 'c-1', data: ['uri' => 'urn:a', 'notation' => 'A']),
				'not an entity',
				self::entity(uuid: 'c-3', data: ['notation' => 'N']),
				self::entity(uuid: 'c-2', data: ['uri' => ' urn:b ']),
			],
		];
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query, $activeOrg, $rbac, $multitenancy) use (&$results) {
				$this->calls[] = [$query, $rbac, $multitenancy];
				return array_shift($results);
			}
		);

		return new ConceptRepository(new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)), $objects);
	}//end repository()

	/**
	 * An object entity with that uuid and data.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()
}//end class
