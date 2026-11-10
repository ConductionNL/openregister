<?php

/**
 * Characterisation tests pinning every branch of the JSON-LD context service
 * and serializer, written against the code before its complexity refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\JsonLd
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\JsonLd;

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializer;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class JsonLdBranchesGoldenTest extends TestCase {
	private const CTX = 'https://nc.test/ctx/reg/sch';

	private JsonLdContextService $context;
	private JsonLdSerializer $serializer;
	private Register $register;

	protected function setUp(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			function (string $route, array $params = []): string {
				if ($route === 'openregister.contexts.schema') {
					return 'https://nc.test/ctx/' . $params['register'] . '/' . $params['schema'];
				}

				return 'https://nc.test/obj/' . $params['register'] . '/' . $params['schema'] . '/' . $params['id'];
			}
		);

		$this->context = new JsonLdContextService($urlGenerator);
		$this->serializer = new JsonLdSerializer($this->context, $urlGenerator);
		$this->register = new Register();
		$this->register->setSlug('reg');
	}

	private function schema(array $properties = [], mixed $configuration = null, int $id = 1, string $slug = 'sch'): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		$schema->setUpdated(new DateTime('2026-02-02T00:00:00+00:00'));
		if ($configuration !== null) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}

	public function testImplementedTypesExplicitListIsAuthoritative(): void {
		$schema = $this->schema(
			[],
			[
				'implements' => ['https://a.test/A', 'notAnIri', 42, 'https://a.test/A', 'urn:x:y'],
				'jsonld' => ['type' => 'https://ignored.test/T'],
				'x-schema-org' => 'schema:Ignored',
			]
		);

		$this->assertSame(['https://a.test/A', 'urn:x:y'], $this->context->getImplementedTypes($schema));
	}

	public function testImplementedTypesUnionsDefaults(): void {
		$schema = $this->schema(
			[],
			[
				'jsonld' => ['type' => 'https://schema.org/Person'],
				'x-schema-org' => ['schema:Person', 'schema:', '', 7, 'https://b.test/B', 'bare', 'schema:Thing'],
			]
		);

		$this->assertSame(
			['https://schema.org/Person', 'https://b.test/B', 'https://schema.org/Thing'],
			$this->context->getImplementedTypes($schema)
		);
	}

	public function testImplementedTypesStringMarkerAndRelativeType(): void {
		$schema = $this->schema([], ['jsonld' => ['type' => 'Person'], 'x-schema-org' => 'schema:Organization']);
		$this->assertSame(['https://schema.org/Organization'], $this->context->getImplementedTypes($schema));
	}

	public function testImplementedTypesEmptyWithoutConfiguration(): void {
		$this->assertSame([], $this->context->getImplementedTypes($this->schema()));
		$this->assertSame([], $this->context->getImplementedTypes($this->schema([], ['jsonld' => 'nope'])));
	}

	public function testSchemaContextFullShapeAndSkips(): void {
		$schema = $this->schema(
			[
				'name' => ['type' => 'string'],
				'' => ['type' => 'string'],
				'7' => ['type' => 'string'],
				'loose' => 'not-an-array',
				'mapped' => ['type' => 'string', 'format' => 'date'],
				'emptyMap' => ['type' => 'string'],
				'org' => ['objectConfiguration' => ['handling' => 'related']],
				'tags' => ['type' => 'array', 'items' => ['objectConfiguration' => []]],
				'plain' => ['type' => 'array', 'items' => ['type' => 'string']],
				'link' => ['format' => 'uri-reference'],
			],
			[
				'jsonld' => [
					'@vocab' => '',
					'type' => '',
					'properties' => ['mapped' => 'https://schema.org/date', 'emptyMap' => ''],
				],
			]
		);

		$this->assertSame(
			[
				'or' => JsonLdContextService::OR_NAMESPACE,
				'xsd' => JsonLdContextService::XSD_NAMESPACE,
				'sch' => self::CTX . '#sch',
				'name' => self::CTX . '#name',
				'loose' => self::CTX . '#loose',
				'mapped' => ['@id' => 'https://schema.org/date', '@type' => 'xsd:date'],
				'emptyMap' => self::CTX . '#emptyMap',
				'org' => ['@id' => self::CTX . '#org', '@type' => '@id'],
				'tags' => ['@id' => self::CTX . '#tags', '@type' => '@id'],
				'plain' => self::CTX . '#plain',
				'link' => ['@id' => self::CTX . '#link', '@type' => '@id'],
			],
			$this->context->buildSchemaContext($this->register, $schema)
		);
	}

	public function testSchemaContextNonArrayPropertyMapAndSlugFallbacks(): void {
		$schema = $this->schema(['a' => []], ['jsonld' => ['properties' => 'bad', '@vocab' => 'https://v.test/']], 5, '');
		$schema->setUuid('uuid-5');

		$this->assertSame(
			[
				'or' => JsonLdContextService::OR_NAMESPACE,
				'xsd' => JsonLdContextService::XSD_NAMESPACE,
				'@vocab' => 'https://v.test/',
				'uuid-5' => 'https://nc.test/ctx/reg/uuid-5#uuid-5',
				'a' => 'https://nc.test/ctx/reg/uuid-5#a',
			],
			$this->context->buildSchemaContext($this->register, $schema)
		);

		$idOnly = $this->schema([], null, 9, '');
		$this->assertSame('9', $this->context->getTypeForSchema($idOnly));
	}

	public function testSchemaContextCacheIsKeyedOnUpdated(): void {
		$schema = $this->schema(['a' => []]);
		$first = $this->context->buildSchemaContext($this->register, $schema);
		$schema->setProperties(['b' => []]);
		$this->assertSame($first, $this->context->buildSchemaContext($this->register, $schema));

		$schema->setUpdated(new DateTime('2026-03-03T00:00:00+00:00'));
		$this->assertArrayHasKey('b', $this->context->buildSchemaContext($this->register, $schema));

		$noStamp = new Schema();
		$noStamp->setId(77);
		$noStamp->setSlug('nostamp');
		$noStamp->setProperties(['c' => []]);
		$this->assertArrayHasKey('c', $this->context->buildSchemaContext($this->register, $noStamp));
	}

	public function testRegisterContextMergesAndKeepsBasePrefixes(): void {
		$one = $this->schema(['a' => []], ['jsonld' => ['@vocab' => 'https://v1.test/']], 1, 's1');
		$two = $this->schema(['a' => ['format' => 'date'], 'b' => []], null, 2, 's2');

		$this->assertSame(
			[
				'or' => JsonLdContextService::OR_NAMESPACE,
				'xsd' => JsonLdContextService::XSD_NAMESPACE,
				'@vocab' => 'https://v1.test/',
				's1' => 'https://nc.test/ctx/reg/s1#s1',
				'a' => ['@id' => 'https://nc.test/ctx/reg/s2#a', '@type' => 'xsd:date'],
				's2' => 'https://nc.test/ctx/reg/s2#s2',
				'b' => 'https://nc.test/ctx/reg/s2#b',
			],
			$this->context->buildRegisterContext($this->register, [$one, 'not-a-schema', $two])
		);
	}

	public function testValidateMappingEveryRefusal(): void {
		$this->assertSame([], $this->context->validateMapping([]));
		$this->assertSame(
			['@vocab must be an absolute IRI'],
			$this->context->validateMapping(['@vocab' => 'relative'])
		);
		$this->assertSame(
			['@vocab must be an absolute IRI'],
			$this->context->validateMapping(['@vocab' => null])
		);
		$this->assertSame(
			[
				'@vocab must be an absolute IRI',
				'type must be an absolute IRI or a term resolvable against @vocab',
				'properties must be an object mapping property names to IRIs',
			],
			$this->context->validateMapping(['@vocab' => 5, 'type' => 'Person', 'properties' => 'x'])
		);
		// A relative @vocab string still serves as a vocabulary base for terms.
		$this->assertSame(
			['@vocab must be an absolute IRI'],
			$this->context->validateMapping(['@vocab' => 'rel', 'type' => 'Person', 'properties' => ['a' => 'term']])
		);
		$this->assertSame(
			[
				'type must be an absolute IRI or a term resolvable against @vocab',
				'properties.a must be an absolute IRI or a term resolvable against @vocab',
				'properties.b must be an absolute IRI or a term resolvable against @vocab',
				'properties.0 must be an absolute IRI or a term resolvable against @vocab',
			],
			$this->context->validateMapping(['type' => '', 'properties' => ['a' => 'term', 'b' => 3, 0 => '', 'c' => 'https://ok.test/c']])
		);
		$this->assertSame(
			['properties.a must be an absolute IRI or a term resolvable against @vocab'],
			$this->context->validateMapping(['@vocab' => 'https://v.test/', 'type' => 'Person', 'properties' => ['a' => 'has space', 'b' => 'term']])
		);
		$this->assertSame(
			['@vocab must be an absolute IRI', 'type must be an absolute IRI or a term resolvable against @vocab'],
			$this->context->validateMapping(['@vocab' => '', 'type' => 'Person'])
		);
	}

	public function testIsAbsoluteIri(): void {
		$this->assertTrue($this->context->isAbsoluteIri('urn:a'));
		$this->assertFalse($this->context->isAbsoluteIri('1urn:a'));
		$this->assertFalse($this->context->isAbsoluteIri(''));
		$this->assertFalse($this->context->isAbsoluteIri(null));
	}

	private function wants(string $accept): bool {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($accept);
		return $this->serializer->wantsJsonLd($request);
	}

	public function testWantsJsonLdEveryBranch(): void {
		$this->assertTrue($this->wants('APPLICATION/LD+JSON'));
		$this->assertTrue($this->wants(' , application/ld+json ;charset=utf-8'));
		$this->assertFalse($this->wants('application/ld+json;q=0'));
		$this->assertFalse($this->wants('application/ld+json;q=0, */*'));
		$this->assertTrue($this->wants('*/*, application/ld+json'));
		$this->assertTrue($this->wants('application/*;q=0.9, application/ld+json;Q=0.9'));
		$this->assertTrue($this->wants('application/ld+json;q=0.9, application/json;q=0.9'));
		$this->assertFalse($this->wants('application/json;q=0.9, application/ld+json;q=0.9'));
		$this->assertTrue($this->wants('application/ld+json;q=0.5;q=0.8, application/json;q=0.7'));
		// First of equal weight and rank wins.
		$this->assertTrue($this->wants('application/ld+json, text/html'));
		$this->assertFalse($this->wants('text/html, application/ld+json'));
		$this->assertTrue($this->wants('text/html;q=0.1, application/ld+json'));
		$this->assertFalse($this->wants('application/ld+json;q=-1'));
		$this->assertFalse($this->wants(';q=1'));
	}

	public function testSerializeNodeShape(): void {
		$schema = $this->schema([], ['jsonld' => ['type' => 'https://schema.org/Person']]);
		$doc = $this->serializer->serialize(
			[
				'name' => 'A',
				'@self' => ['id' => 'u1', 'uri' => '', 'empty' => '', 'nil' => null, 'list' => [], 'zero' => 0, 'owner' => 'me'],
				'@odd' => 1,
				3 => 'num',
			],
			$schema,
			$this->register
		);

		$this->assertSame(
			[
				'@context' => self::CTX,
				'@id' => 'https://nc.test/obj/reg/sch/u1',
				'@type' => 'https://schema.org/Person',
				'or:id' => 'u1',
				'or:zero' => 0,
				'or:owner' => 'me',
				'name' => 'A',
				'or:raw#odd' => 1,
				// array_merge renumbers integer keys.
				0 => 'num',
			],
			$doc
		);
	}

	public function testSerializeWithoutSelf(): void {
		$doc = $this->serializer->serialize(['@self' => 'bad', 'x' => 1], $this->schema(), $this->register);
		$this->assertSame(
			['@context' => self::CTX, '@id' => 'https://nc.test/obj/reg/sch/', '@type' => 'sch', 'x' => 1],
			$doc
		);
	}

	public function testSerializeCollectionFullMetadata(): void {
		$doc = $this->serializer->serializeCollection(
			[
				'results' => [['@self' => ['uri' => 'https://o.test/1']], 'skip', ['@self' => ['uri' => 'https://o.test/2']]],
				'limit' => 5,
				'pages' => null,
				'page' => 2,
				'total' => 0,
				'next' => 'https://next.test',
			],
			$this->schema(),
			$this->register
		);

		$this->assertSame(
			[
				'@context' => self::CTX,
				'@graph' => [
					['@id' => 'https://o.test/1', '@type' => 'sch'],
					['@id' => 'https://o.test/2', '@type' => 'sch'],
				],
				'or:total' => 0,
				'or:page' => 2,
				'or:pages' => null,
				'or:limit' => 5,
				'or:next' => 'https://next.test',
			],
			$doc
		);
	}

	public function testSerializeCollectionNonArrayResultsAndNoMetadata(): void {
		$this->assertSame(
			['@context' => self::CTX, '@graph' => []],
			$this->serializer->serializeCollection(['results' => 'x'], $this->schema(), $this->register)
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function nextLinkCases(): array {
		return [
			'non-string next ignored, derives' => [['next' => 5, 'page' => 1, 'pages' => 2, '@self' => ['self' => 'https://s.test/x']], 'https://s.test/x?_page=2'],
			'existing query' => [['page' => 3, 'pages' => 9, '@self' => ['self' => 'https://s.test/x?a=1']], 'https://s.test/x?a=1&_page=4'],
			'last page' => [['page' => 2, 'pages' => 2, '@self' => ['self' => 'https://s.test/x']], null],
			'beyond last' => [['page' => 3, 'pages' => 2, '@self' => ['self' => 'https://s.test/x']], null],
			'string page' => [['page' => '1', 'pages' => 2, '@self' => ['self' => 'https://s.test/x']], null],
			'string pages' => [['page' => 1, 'pages' => '2', '@self' => ['self' => 'https://s.test/x']], null],
			'no self' => [['page' => 1, 'pages' => 2], null],
			'empty self' => [['page' => 1, 'pages' => 2, '@self' => ['self' => '']], null],
			'non-string self' => [['page' => 1, 'pages' => 2, '@self' => ['self' => 3]], null],
			'null next' => [['next' => null, 'page' => 1, 'pages' => 2, '@self' => ['self' => 'https://s.test/x']], 'https://s.test/x?_page=2'],
		];
	}

	/**
	 * @dataProvider nextLinkCases
	 */
	public function testNextLink(array $result, ?string $expected): void {
		$doc = $this->serializer->serializeCollection($result + ['results' => []], $this->schema(), $this->register);
		$this->assertSame($expected, ($doc['or:next'] ?? null));
	}
}
