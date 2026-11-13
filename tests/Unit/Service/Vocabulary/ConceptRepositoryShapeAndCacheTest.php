<?php

/**
 * Covers ConceptRepository's concept shape, its read-failure answer and its cache reset.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * conceptShape() answers the scheme's stored shape or an empty one; a failing read answers nothing; clearCache() re-reads.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptRepository
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class ConceptRepositoryShapeAndCacheTest extends TestCase {

	/**
	 * The queries the repository sent to the object layer.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * How often the vocabulary register was looked up.
	 *
	 * @var integer
	 */
	private int $registerLookups = 0;

	/**
	 * A repository over register 7, scheme schema 8 and concept schema 9.
	 *
	 * @param list<mixed> $results What each search answers, in order; a Throwable is thrown.
	 *
	 * @return ConceptRepository
	 */
	private function repository(array $results): ConceptRepository {
		$register = new Register();
		$register->setId(7);
		$schemaIds = [];
		foreach ([8, 9] as $id) {
			$schema = new Schema();
			$schema->setId($id);
			$schemaIds[] = $schema;
		}

		$registers = $this->createMock(originalClassName: RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function () use ($register): Register {
				$this->registerLookups++;
				return $register;
			}
		);
		$schemas = $this->createMock(originalClassName: SchemaMapperInterface::class);
		$calls   = 0;
		$schemas->method('find')->willReturnCallback(
			static function () use (&$calls, $schemaIds): Schema {
				return $schemaIds[($calls++ % 2)];
			}
		);

		$objects = $this->createMock(originalClassName: MagicMapperInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$results) {
				$this->queries[] = $query;
				$next = array_shift($results);
				if ($next instanceof \Throwable) {
					throw $next;
				}

				return $next;
			}
		);

		$catalog = new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(originalClassName: MagicMapperInterface::class));

		return new ConceptRepository(catalog: $catalog, objects: $objects);
	}//end repository()

	/**
	 * A scheme object with that data.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function scheme(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$entity->setObject(['uri' => 'https://x/s'] + $data);

		return $entity;
	}//end scheme()

	/**
	 * The scheme's stored concept shape is answered as stored.
	 *
	 * @return void
	 */
	public function testTheStoredShapeIsAnswered(): void {
		$shape      = ['required' => ['notation'], 'properties' => ['notation' => ['type' => 'string']]];
		$repository = $this->repository(results: [[self::scheme(data: ['conceptShape' => $shape])]]);

		$this->assertSame(expected: $shape, actual: $repository->conceptShape(schemeUri: 'https://x/s'));
	}//end testTheStoredShapeIsAnswered()

	/**
	 * A shape that is not an object, a scheme without one and an unknown scheme all answer an empty shape.
	 *
	 * @return void
	 */
	public function testAMissingOrMalformedShapeIsEmpty(): void {
		$malformed = $this->repository(results: [[self::scheme(data: ['conceptShape' => 'strict'])]]);
		$this->assertSame(expected: [], actual: $malformed->conceptShape(schemeUri: 'https://x/s'));
		$without = $this->repository(results: [[self::scheme(data: [])]]);
		$this->assertSame(expected: [], actual: $without->conceptShape(schemeUri: 'https://x/s'));
		$this->assertSame(expected: [], actual: $this->repository(results: [[]])->conceptShape(schemeUri: 'https://x/none'));
	}//end testAMissingOrMalformedShapeIsEmpty()

	/**
	 * A failing object read answers no scheme and no concepts instead of throwing.
	 *
	 * @return void
	 */
	public function testAFailingReadAnswersNothing(): void {
		$repository = $this->repository(results: [new RuntimeException('table gone')]);

		$this->assertNull(actual: $repository->scheme(schemeUri: 'https://x/s'));
		$this->assertSame(expected: [], actual: $repository->conceptsOf(schemeUri: 'https://x/s'));
		$this->assertCount(expectedCount: 1, haystack: $this->queries, message: 'The cached miss is not read again');
	}//end testAFailingReadAnswersNothing()

	/**
	 * Concepts of an unknown scheme are empty and no concept query is sent.
	 *
	 * @return void
	 */
	public function testConceptsOfAnUnknownSchemeAreEmpty(): void {
		$repository = $this->repository(results: [[]]);

		$this->assertSame(expected: [], actual: $repository->conceptsOf(schemeUri: 'https://x/none'));
		$this->assertSame(expected: [['uri' => 'https://x/none', '@self' => ['register' => 7, 'schema' => 8], '_limit' => 1]], actual: $this->queries);
	}//end testConceptsOfAnUnknownSchemeAreEmpty()

	/**
	 * After clearCache() the ids are resolved and the scheme read again.
	 *
	 * @return void
	 */
	public function testClearCacheReadsAgain(): void {
		$repository = $this->repository(results: [[self::scheme(data: ['title' => 'Old'])], [self::scheme(data: ['title' => 'New'])]]);

		$this->assertSame(expected: 'Old', actual: $repository->scheme(schemeUri: 'https://x/s')['title']);
		$this->assertSame(expected: 'Old', actual: $repository->scheme(schemeUri: 'https://x/s')['title']);
		$repository->clearCache();

		$this->assertSame(expected: 'New', actual: $repository->scheme(schemeUri: 'https://x/s')['title']);
		$this->assertCount(expectedCount: 2, haystack: $this->queries);
		$this->assertSame(expected: 2, actual: $this->registerLookups);
	}//end testClearCacheReadsAgain()
}//end class
