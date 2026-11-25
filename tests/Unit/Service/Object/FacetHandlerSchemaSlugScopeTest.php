<?php

/**
 * Facet discovery resolves a `@self.schema` slug inside the query's register.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\FacetLookups;
use OCA\OpenRegister\Service\Object\FacetResponseCache;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Two registers carry a schema with one slug; the facet query's register decides which one is read.
 *
 * @covers \OCA\OpenRegister\Service\Object\FacetHandler
 * @uses   \OCA\OpenRegister\Service\Object\FacetLookups
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class FacetHandlerSchemaSlugScopeTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private RegisterMapperInterface&MockObject $registers;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');

		return $schema;
	}

	/**
	 * The schemas facet discovery reads for this query.
	 *
	 * @param array<string, mixed>         $query     The facet query.
	 * @param RegisterMapperInterface|null $registers The register reader, or none.
	 *
	 * @return array<int, Schema>
	 */
	private function schemasFor(array $query, ?RegisterMapperInterface $registers): array {
		$handler = new FacetHandler(
			lookups: new FacetLookups($this->createMock(MagicMapper::class), $this->schemas, $registers),
			responseCache: $this->createMock(FacetResponseCache::class),
			logger: new NullLogger(),
			searchProfile: new PropertySearchProfile()
		);

		return (new ReflectionMethod(FacetHandler::class, 'getSchemasForQuery'))->invoke($handler, $query);
	}

	public function testASlugResolvesInsideTheQueriedRegister(): void {
		$register = new Register();
		$register->setSchemas([36, 37]);
		$this->registers->expects($this->once())->method('find')->with(5, false, false)->willReturn($register);
		$this->schemas->expects($this->once())->method('findBySlugInIds')->with('agent', [36, 37])->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('find');

		$found = $this->schemasFor(query: ['@self' => ['register' => 5, 'schema' => 'agent']], registers: $this->registers);

		$this->assertSame([36], array_map(static fn (Schema $s): int => $s->getId(), $found));
	}

	public function testASlugTheRegisterLacksResolvesGloballyWithoutRbac(): void {
		$register = new Register();
		$register->setSchemas([40]);
		$this->registers->method('find')->willReturn($register);
		$this->schemas->method('findBySlugInIds')->willReturn(null);
		$this->schemas->expects($this->once())->method('find')->with('agent', [], false, false)->willReturn(self::schema(22));

		$found = $this->schemasFor(query: ['@self' => ['register' => 5, 'schema' => 'agent']], registers: $this->registers);

		$this->assertSame([22], array_map(static fn (Schema $s): int => $s->getId(), $found));
	}

	public function testAListOfRegistersScopesNothing(): void {
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->expects($this->once())->method('find')->with('agent')->willReturn(self::schema(22));

		$found = $this->schemasFor(query: ['@self' => ['register' => [5, 6], 'schema' => 'agent']], registers: $this->registers);

		$this->assertSame([22], array_map(static fn (Schema $s): int => $s->getId(), $found));
	}

	public function testAnUnknownSchemaStillAnswersNoSchemas(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('none'));

		$this->assertSame([], $this->schemasFor(query: ['@self' => ['register' => 5, 'schema' => 'nope']], registers: null));
	}
}
