<?php

/**
 * Pins how ConceptRepository finds a concept scheme by uri and by reference.
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
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptRepository
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 */
final class ConceptRepositorySchemeGoldenTest extends TestCase {

	/**
	 * The queries the repository sent to the object layer.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A repository over a vocabulary register 7 with scheme schema 8 and concept schema 9.
	 *
	 * @param list<mixed> $results What each search answers, in order.
	 *
	 * @return ConceptRepository
	 */
	private function repository(array $results): ConceptRepository {
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

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$results) {
				$this->queries[] = $query;
				return array_shift($results);
			}
		);

		return new ConceptRepository(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $objects);
	}//end repository()

	/**
	 * A scheme object with that uuid and data.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * A scheme found by uri carries its uuid, and the second lookup is cached.
	 *
	 * @return void
	 */
	public function testSchemeByUriIsReadOnceAndCarriesItsUuid(): void {
		$repository = $this->repository(results: [[$this->entity('u-1', ['uri' => 'https://x/s', 'title' => 'S'])]]);

		$expected = ['id' => 'u-1', 'uri' => 'https://x/s', 'title' => 'S', '@uuid' => 'u-1'];
		$this->assertSame($expected, $repository->scheme('https://x/s'));
		$this->assertSame($expected, $repository->scheme('https://x/s'));
		$this->assertSame([['uri' => 'https://x/s', '@self' => ['register' => 7, 'schema' => 8], '_limit' => 1]], $this->queries);
	}//end testSchemeByUriIsReadOnceAndCarriesItsUuid()

	/**
	 * No match, a non-entity and a read failure all answer null; a miss is cached.
	 *
	 * @return void
	 */
	public function testAMissAnswersNullAndIsCached(): void {
		$repository = $this->repository(results: [[], ['not an entity']]);

		$this->assertNull($repository->scheme('https://x/none'));
		$this->assertNull($repository->scheme('https://x/none'));
		$this->assertNull($repository->scheme('https://x/other'));
		// A uri miss falls back to the slug (property-code-list-from-concept-scheme 4.4);
		// neither reference is uuid-shaped, so no uuid read follows. The repeat is cached.
		$this->assertCount(4, $this->queries);
		$this->assertSame(['@self' => ['slug' => 'https://x/none', 'register' => 7, 'schema' => 8], '_limit' => 1], $this->queries[1]);
	}//end testAMissAnswersNullAndIsCached()

	/**
	 * A scheme found by reference is read by uuid and then cached under its uri.
	 *
	 * @return void
	 */
	public function testSchemeByReferenceIsCachedUnderItsUri(): void {
		$repository = $this->repository(results: [[$this->entity('u-2', ['uri' => ' https://x/r '])]]);

		$expected = ['id' => 'u-2', 'uri' => ' https://x/r ', '@uuid' => 'u-2'];
		$this->assertSame($expected, $repository->schemeByReference('u-2'));
		$this->assertSame($expected, $repository->schemeByReference('u-2'));
		$this->assertSame($expected, $repository->scheme('https://x/r'));
		$this->assertSame([['@self' => ['uuid' => 'u-2', 'register' => 7, 'schema' => 8], '_limit' => 1]], $this->queries);
	}//end testSchemeByReferenceIsCachedUnderItsUri()

	/**
	 * Without the vocabulary register nothing is read and every lookup answers null.
	 *
	 * @return void
	 */
	public function testWithoutTheVocabularyRegisterNothingIsRead(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willThrowException(new RuntimeException('missing'));
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->expects($this->never())->method('searchObjects');
		$repository = new ConceptRepository(new SchemaCatalog($registers, $this->createMock(SchemaMapperInterface::class), $this->createMock(MagicMapperInterface::class)), $objects);

		$this->assertNull($repository->scheme('https://x/s'));
		$this->assertNull($repository->schemeByReference('u-1'));
	}//end testWithoutTheVocabularyRegisterNothingIsRead()
}//end class
