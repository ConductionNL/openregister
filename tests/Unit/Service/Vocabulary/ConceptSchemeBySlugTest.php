<?php

/**
 * ConceptSchemeBySlugTest — a property bound by the `conceptScheme` modifier
 * names its scheme by slug, as the modifier's published description says, and
 * every reader of the binding finds that scheme: the options, the value-in-scheme
 * check and the filter expansion (property-code-list-from-concept-scheme 4.4).
 *
 * The scheme's uri here is not its slug, which is the case that returned no
 * options before: the binding read `gemeenten` and the lookup asked for a scheme
 * whose uri is `gemeenten`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
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
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Vocabulary\BranchFilter;
use OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionScope;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\OpenRegister\Service\Vocabulary\ConceptSchemeAccess;
use PHPUnit\Framework\TestCase;

final class ConceptSchemeBySlugTest extends TestCase {

	private const SCHEME_UUID = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';

	private const SCHEME_URI = 'https://example.org/gemeenten';

	/**
	 * Every query the object layer was asked, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * The repository over an object layer holding one scheme, slug `gemeenten`,
	 * and its three concepts.
	 *
	 * @return ConceptRepository
	 */
	private function repository(): ConceptRepository {
		$register = new Register();
		$register->setId(7);
		$schemeSchema = new Schema();
		$schemeSchema->setId(8);
		$conceptSchema = new Schema();
		$conceptSchema->setId(9);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnOnConsecutiveCalls($schemeSchema, $conceptSchema);

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->queries[] = $query;
				$self = ($query['@self'] ?? []);

				if (($self['schema'] ?? null) === 9) {
					if (($query['inScheme'] ?? null) !== self::SCHEME_UUID) {
						return [];
					}

					return [
						self::entity('c-1', ['uri' => 'https://example.org/gemeenten/utrecht', 'notation' => '0344', 'prefLabel' => ['nl' => 'Utrecht']]),
						self::entity('c-2', ['uri' => 'https://example.org/gemeenten/utrecht-centrum', 'notation' => '0344-01', 'prefLabel' => ['nl' => 'Centrum'], 'broader' => 'https://example.org/gemeenten/utrecht']),
						self::entity('c-3', ['uri' => 'https://example.org/gemeenten/zeist', 'notation' => '0355', 'prefLabel' => ['nl' => 'Zeist']]),
					];
				}

				if (($self['slug'] ?? null) === 'gemeenten' || ($self['uuid'] ?? null) === self::SCHEME_UUID || ($query['uri'] ?? null) === self::SCHEME_URI) {
					return [self::entity(self::SCHEME_UUID, ['uri' => self::SCHEME_URI, 'title' => 'Gemeenten'])];
				}

				return [];
			}
		);

		return new ConceptRepository(
			catalog: new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)),
			objects: $objects
		);
	}

	/**
	 * An object entity with the given uuid and data.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}

	/**
	 * The declaration a `conceptScheme: gemeenten` property makes.
	 *
	 * @return CodedPropertyDeclaration
	 */
	private static function declaration(): CodedPropertyDeclaration {
		$declaration = (new CodedPropertyDeclarationFactory())->fromProperty(
			['type' => 'string', CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'gemeenten']
		);
		self::assertNotNull($declaration);
		self::assertSame('gemeenten', $declaration->scheme, 'the slug is passed on as the binding');

		return $declaration;
	}

	/**
	 * The scheme access over the repository, as the readers take it.
	 *
	 * @param ConceptRepository $repository The repository.
	 *
	 * @return ConceptSchemeAccess
	 */
	private static function access(ConceptRepository $repository): ConceptSchemeAccess {
		$lifecycle = new ConceptLifecycle();
		return new ConceptSchemeAccess($repository, new ConceptHierarchy($lifecycle), $lifecycle);
	}

	public function testASlugFindsTheSchemeAfterTheUriMisses(): void {
		$repository = $this->repository();

		$scheme = $repository->scheme('gemeenten');

		$this->assertSame(self::SCHEME_URI, $scheme['uri']);
		$this->assertSame(self::SCHEME_UUID, $scheme['@uuid']);
		$this->assertSame('gemeenten', $this->queries[0]['uri'], 'the uri is asked first');
		$this->assertSame('gemeenten', $this->queries[1]['@self']['slug']);
		$this->assertCount(2, $this->queries, 'a slug hit asks no uuid');
	}

	public function testTheBindingsOptionsAreTheSchemesConcepts(): void {
		$options = (new CodedOptionsBuilder(self::access($this->repository())))->options(
			self::declaration(),
			new CodedOptionScope(language: 'nl', asOf: new DateTimeImmutable('2026-11-13T00:00:00+00:00'))
		);

		$this->assertSame(['Utrecht', 'Centrum', 'Zeist'], array_column($options, 'label'));
		$this->assertSame('https://example.org/gemeenten/utrecht', $options[0]['value']);
	}

	public function testTheValueCheckFindsAValueOfTheSchemeAndNotAnother(): void {
		$repository = $this->repository();
		$declaration = self::declaration();

		$this->assertSame('0344', $repository->resolve('https://example.org/gemeenten/utrecht', $declaration)['notation']);
		$this->assertNull($repository->resolve('https://example.org/gemeenten/amsterdam', $declaration));
	}

	public function testAFilterOnABranchExpandsToItsConcepts(): void {
		$expander = new CodedFilterExpander(
			self::access($this->repository()),
			new CodedSchemaTables(
				$this->createMock(SchemaMapperInterface::class),
				new CodedPropertyDeclarationFactory(),
				$this->createMock(MagicMapperInterface::class)
			)
		);

		$values = $expander->valuesForBranch(self::declaration(), new BranchFilter('https://example.org/gemeenten/utrecht'));

		$this->assertSame(
			['https://example.org/gemeenten/utrecht', 'https://example.org/gemeenten/utrecht-centrum'],
			$values
		);
	}

	public function testAUuidFindsTheSchemeWhenNeitherUriNorSlugDoes(): void {
		$repository = $this->repository();

		$this->assertSame(self::SCHEME_URI, $repository->scheme(self::SCHEME_UUID)['uri']);
		$this->assertSame(self::SCHEME_UUID, $this->queries[2]['@self']['uuid']);
	}

	public function testTheUriStillWinsAndAnUnknownReferenceAsksNoUuid(): void {
		$repository = $this->repository();

		$this->assertSame(self::SCHEME_URI, $repository->scheme(self::SCHEME_URI)['uri']);
		$this->assertCount(1, $this->queries, 'a uri hit asks nothing more');

		$this->assertNull($repository->scheme('provincies'));
		$this->assertCount(3, $this->queries, 'uri and slug, and no uuid read for a non-uuid reference');
		$this->assertNull($repository->scheme(''));
	}
}
