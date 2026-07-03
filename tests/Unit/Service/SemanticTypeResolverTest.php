<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\SemanticTypeResolver}.
 *
 * Covers null-safe resolution (0 providers → null), single-provider
 * resolution, multi-provider deterministic tie-break + WARN, the
 * implemented-types default from `jsonld.type`, multi-value `implements`,
 * and schema-level `x-schema-org` CURIE expansion.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SemanticTypeResolverTest.
 */
class SemanticTypeResolverTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;

    private RegisterMapper&MockObject $registerMapper;

    private JsonLdContextService $jsonLd;

    private LoggerInterface&MockObject $logger;

    private SemanticTypeResolver $resolver;

    private const ORG_URI = 'https://schema.org/Organization';


    protected function setUp(): void
    {
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        // Real JsonLdContextService — its implemented-types logic is under test too.
        $this->jsonLd = new JsonLdContextService($this->createMock(IURLGenerator::class));
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resolver = new SemanticTypeResolver(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            jsonLdContextService: $this->jsonLd,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Build a real Schema entity with the given id/slug/configuration.
     *
     * The Schema getters are NC-Entity magic methods that cannot be mocked,
     * so real entities are used and hydrated via their setters.
     *
     * @param int                  $id            Schema id.
     * @param string               $slug          Schema slug.
     * @param array<string, mixed> $configuration Schema configuration block.
     *
     * @return Schema
     */
    private function schema(int $id, string $slug, array $configuration): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->setUuid('uuid-'.$id);
        $schema->setConfiguration($configuration);
        return $schema;

    }//end schema()


    /**
     * Build a real Register entity with the given id/slug/app/schema-ids.
     *
     * @param int             $id        Register id.
     * @param string          $slug      Register slug.
     * @param string|null     $app       Register application id.
     * @param array<int, int> $schemaIds Member schema ids.
     *
     * @return Register
     */
    private function register(int $id, string $slug, ?string $app, array $schemaIds): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setSlug($slug);
        if ($app !== null) {
            $register->setApplication($app);
        }

        $register->setSchemas($schemaIds);
        return $register;

    }//end register()


    public function testNoProviderResolvesToNull(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([
            $this->schema(id: 1, slug: 'invoice', configuration: []),
        ]);

        $this->assertNull($this->resolver->resolveSchemaByImplements(uri: self::ORG_URI));

    }//end testNoProviderResolvesToNull()


    public function testSingleProviderResolves(): void
    {
        $payee = $this->schema(
            id: 42,
            slug: 'payee',
            configuration: ['jsonld' => ['type' => self::ORG_URI]]
        );
        $this->schemaMapper->method('findAll')->willReturn([
            $this->schema(id: 1, slug: 'invoice', configuration: []),
            $payee,
        ]);

        $result = $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI);

        $this->assertNotNull($result);
        $this->assertSame(42, $result->getId());
        $this->assertSame('payee', $result->getSlug());

    }//end testSingleProviderResolves()


    public function testImplementedTypesDefaultFromJsonLdType(): void
    {
        // A schema whose only marker is jsonld.type must be discoverable.
        $s = $this->schema(id: 7, slug: 'org', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $this->assertSame([self::ORG_URI], $this->jsonLd->getImplementedTypes(schema: $s));

    }//end testImplementedTypesDefaultFromJsonLdType()


    public function testMultiValueImplements(): void
    {
        $vendor = 'https://openregister.app/ns#Vendor';
        $s      = $this->schema(
            id: 9,
            slug: 'payee',
            configuration: ['implements' => [self::ORG_URI, $vendor]]
        );

        $types = $this->jsonLd->getImplementedTypes(schema: $s);
        $this->assertContains(self::ORG_URI, $types);
        $this->assertContains($vendor, $types);
        $this->assertCount(2, $types);

    }//end testMultiValueImplements()


    public function testImplementsDropsNonIri(): void
    {
        $s = $this->schema(
            id: 9,
            slug: 'payee',
            configuration: ['implements' => ['Organization', self::ORG_URI]]
        );

        $this->assertSame([self::ORG_URI], $this->jsonLd->getImplementedTypes(schema: $s));

    }//end testImplementsDropsNonIri()


    public function testSchemaOrgCurieExpansion(): void
    {
        // Schema-level x-schema-org CURIE must expand to an absolute schema.org IRI.
        $s = $this->schema(
            id: 11,
            slug: 'payee',
            configuration: ['x-schema-org' => 'schema:Organization']
        );

        $this->assertSame([self::ORG_URI], $this->jsonLd->getImplementedTypes(schema: $s));

        // And such a schema resolves via the URI.
        $this->schemaMapper->method('findAll')->willReturn([$s]);
        $result = $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI);
        $this->assertNotNull($result);
        $this->assertSame(11, $result->getId());

    }//end testSchemaOrgCurieExpansion()


    public function testSchemaOrgCurieArrayForm(): void
    {
        $s = $this->schema(
            id: 12,
            slug: 'multi',
            configuration: ['x-schema-org' => ['schema:Organization', 'schema:Person']]
        );

        $types = $this->jsonLd->getImplementedTypes(schema: $s);
        $this->assertContains(self::ORG_URI, $types);
        $this->assertContains('https://schema.org/Person', $types);

    }//end testSchemaOrgCurieArrayForm()


    public function testMultipleProvidersDeterministicTieBreakAndWarns(): void
    {
        // Two schemas implement the same URI; expect the first by slug and a WARN.
        $alpha = $this->schema(id: 100, slug: 'alpha', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $beta  = $this->schema(id: 200, slug: 'beta', configuration: ['jsonld' => ['type' => self::ORG_URI]]);

        // Deliberately unsorted input to prove ordering is applied internally.
        $this->schemaMapper->method('findAll')->willReturn([$beta, $alpha]);

        $this->logger->expects($this->once())->method('warning');

        $result = $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI);

        $this->assertNotNull($result);
        $this->assertSame('alpha', $result->getSlug());

    }//end testMultipleProvidersDeterministicTieBreakAndWarns()


    public function testTieBreakPrefersConsumingRegister(): void
    {
        $alpha = $this->schema(id: 100, slug: 'alpha', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $beta  = $this->schema(id: 200, slug: 'beta', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $this->schemaMapper->method('findAll')->willReturn([$alpha, $beta]);

        // Consuming register 5 contains schema 200 (beta) — beta must win over
        // the slug-first default (alpha).
        $consuming = $this->register(id: 5, slug: 'consumer', app: null, schemaIds: [200]);
        $this->registerMapper->method('find')->willReturn($consuming);

        $this->logger->expects($this->once())->method('warning');

        $result = $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI, consumingRegisterId: 5);

        $this->assertNotNull($result);
        $this->assertSame('beta', $result->getSlug());

    }//end testTieBreakPrefersConsumingRegister()


    public function testResolutionIsRequestCached(): void
    {
        // findAll must be hit at most once for repeated same-uri lookups.
        $payee = $this->schema(id: 42, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $this->schemaMapper->expects($this->once())->method('findAll')->willReturn([$payee]);

        $first  = $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI);
        $second = $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI);

        $this->assertSame($first, $second);

    }//end testResolutionIsRequestCached()


    public function testFindRegisterForSchemaMatchesMembership(): void
    {
        $payee = $this->schema(id: 42, slug: 'payee', configuration: []);

        $reg = $this->register(id: 3, slug: 'shillinq', app: 'shillinq', schemaIds: [1, 42, 99]);
        $this->registerMapper->method('findAll')->willReturn([$reg]);

        $result = $this->resolver->findRegisterForSchema(schema: $payee);

        $this->assertNotNull($result);
        $this->assertSame('shillinq', $result->getSlug());

    }//end testFindRegisterForSchemaMatchesMembership()
}//end class
