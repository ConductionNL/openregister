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
use OCP\App\IAppManager;
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

    private IAppManager&MockObject $appManager;

    private SemanticTypeResolver $resolver;

    private const ORG_URI = 'https://schema.org/Organization';


    protected function setUp(): void
    {
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        // Real JsonLdContextService — its implemented-types logic is under test too.
        $this->jsonLd     = new JsonLdContextService($this->createMock(IURLGenerator::class));
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        // Default: every app enabled, so the app-enabled gate is a no-op unless
        // a test overrides it (the interesting disabled-app cases do so below).
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->appManager->method('isInstalled')->willReturn(true);

        $this->resolver = new SemanticTypeResolver(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            jsonLdContextService: $this->jsonLd,
            logger: $this->logger,
            appManager: $this->appManager,
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


    public function testDisabledProviderAppDegradesToNull(): void
    {
        // Payee implements the URI but its owning app (shillinq) is disabled;
        // resolution must degrade to null as if no provider were installed. The
        // owning app is read from the schema's own `application` field — the
        // reliable per-schema signal on real fleet schemas.
        $payee = $this->schema(id: 42, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $payee->setApplication('shillinq');
        $this->schemaMapper->method('findAll')->willReturn([$payee]);

        // Fresh app manager so we can assert the disabled outcome.
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->with('shillinq')->willReturn(false);
        $resolver = new SemanticTypeResolver(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            jsonLdContextService: $this->jsonLd,
            logger: $this->logger,
            appManager: $appManager,
        );

        $this->assertNull($resolver->resolveSchemaByImplements(uri: self::ORG_URI));

    }//end testDisabledProviderAppDegradesToNull()


    public function testEnabledProviderAppResolves(): void
    {
        // Same shape, but shillinq is enabled → Payee resolves.
        $payee = $this->schema(id: 42, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $payee->setApplication('shillinq');
        $this->schemaMapper->method('findAll')->willReturn([$payee]);

        $result = $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI);

        $this->assertNotNull($result);
        $this->assertSame(42, $result->getId());

    }//end testEnabledProviderAppResolves()


    public function testDisabledAppDeterminedFromRegisterWhenSchemaHasNoApp(): void
    {
        // A schema with no `application` of its own falls back to the owning
        // register's `application` for the enabled check.
        $payee = $this->schema(id: 42, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $this->schemaMapper->method('findAll')->willReturn([$payee]);

        $reg = $this->register(id: 3, slug: 'shillinq', app: 'shillinq', schemaIds: [42]);
        $this->registerMapper->method('findAll')->willReturn([$reg]);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->with('shillinq')->willReturn(false);
        $resolver = new SemanticTypeResolver(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            jsonLdContextService: $this->jsonLd,
            logger: $this->logger,
            appManager: $appManager,
        );

        $this->assertNull($resolver->resolveSchemaByImplements(uri: self::ORG_URI));

    }//end testDisabledAppDeterminedFromRegisterWhenSchemaHasNoApp()


    public function testAppEnabledCheckIsNullSafeWithoutAppManager(): void
    {
        // With no app manager injected the gate is skipped entirely — a provider
        // still resolves even though its owning app cannot be queried.
        $payee = $this->schema(id: 42, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $this->schemaMapper->method('findAll')->willReturn([$payee]);

        $resolver = new SemanticTypeResolver(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            jsonLdContextService: $this->jsonLd,
            logger: $this->logger,
            appManager: null,
        );

        $result = $resolver->resolveSchemaByImplements(uri: self::ORG_URI);
        $this->assertNotNull($result);
        $this->assertSame(42, $result->getId());

    }//end testAppEnabledCheckIsNullSafeWithoutAppManager()


    public function testCoreOpenregisterAppNotFilteredOut(): void
    {
        // A provider owned by core `openregister` must never be filtered out by
        // the app-enabled gate, regardless of the app manager's answer.
        $payee = $this->schema(id: 42, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG_URI]]);
        $this->schemaMapper->method('findAll')->willReturn([$payee]);

        $reg = $this->register(id: 1, slug: 'core', app: 'openregister', schemaIds: [42]);
        $this->registerMapper->method('findAll')->willReturn([$reg]);

        // Even if isEnabledForUser said false, the 'openregister' short-circuit wins.
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(false);
        $resolver = new SemanticTypeResolver(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            jsonLdContextService: $this->jsonLd,
            logger: $this->logger,
            appManager: $appManager,
        );

        $this->assertNotNull($resolver->resolveSchemaByImplements(uri: self::ORG_URI));

    }//end testCoreOpenregisterAppNotFilteredOut()


    private const PERSON_URI = 'https://schema.org/Person';


    /**
     * A child schema that `allOf`-extends a Person-marked schema, and declares no
     * marker of its own, implements Person via inheritance and resolves for the
     * Person URI (tasks.md §1.1/§1.2).
     *
     * @return void
     */
    public function testChildAllOfInheritsPersonType(): void
    {
        $base = $this->schema(id: 10, slug: 'base-person', configuration: ['x-schema-org' => 'schema:Person']);

        $citizen = $this->schema(id: 11, slug: 'citizen', configuration: []);
        $citizen->setAllOf(['10']);

        // Only the child is enumerated; the parent is loaded via find().
        $this->schemaMapper->method('findAll')->willReturn([$citizen]);
        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($base) {
                if ((string) $id === '10') {
                    return $base;
                }

                throw new \Exception('not found');
            }
        );

        $result = $this->resolver->resolveSchemaByImplements(uri: self::PERSON_URI);
        $this->assertNotNull($result);
        $this->assertSame('citizen', $result->getSlug());

    }//end testChildAllOfInheritsPersonType()


    /**
     * A child's own markers and its `allOf` ancestor's markers union: it resolves
     * for both its own Vendor IRI and the inherited Organization URI.
     *
     * @return void
     */
    public function testOwnAndAncestorMarkersUnion(): void
    {
        $vendor = 'https://openregister.app/ns#Vendor';

        $orgBase = $this->schema(id: 20, slug: 'org-base', configuration: ['x-schema-org' => 'schema:Organization']);

        $child = $this->schema(id: 21, slug: 'vendor-child', configuration: ['implements' => [$vendor]]);
        $child->setAllOf(['20']);

        $this->schemaMapper->method('findAll')->willReturn([$child]);
        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($orgBase) {
                if ((string) $id === '20') {
                    return $orgBase;
                }

                throw new \Exception('not found');
            }
        );

        // Resolves for the inherited Organization URI.
        $this->assertSame('vendor-child', $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI)?->getSlug());
        // And for its own Vendor IRI.
        $this->assertSame('vendor-child', $this->resolver->resolveSchemaByImplements(uri: $vendor)?->getSlug());

    }//end testOwnAndAncestorMarkersUnion()


    /**
     * A schema with no `allOf` is unaffected — it resolves for exactly its own
     * markers and nothing more (no regression from the ancestor walk).
     *
     * @return void
     */
    public function testNoAllOfIsUnchanged(): void
    {
        $s = $this->schema(id: 30, slug: 'plain-org', configuration: ['x-schema-org' => 'schema:Organization']);
        // find() must never be needed when there is no allOf.
        $this->schemaMapper->expects($this->never())->method('find');
        $this->schemaMapper->method('findAll')->willReturn([$s]);

        $this->assertSame('plain-org', $this->resolver->resolveSchemaByImplements(uri: self::ORG_URI)?->getSlug());
        $this->assertNull($this->resolver->resolveSchemaByImplements(uri: self::PERSON_URI));

    }//end testNoAllOfIsUnchanged()


    /**
     * A cyclic `allOf` (A extends B, B extends A) must not loop — the visited-set
     * guard terminates the ancestor walk and resolution still returns.
     *
     * @return void
     */
    public function testCircularAllOfDoesNotLoop(): void
    {
        $schemaA = $this->schema(id: 40, slug: 'a', configuration: ['x-schema-org' => 'schema:Person']);
        $schemaA->setAllOf(['41']);
        $schemaB = $this->schema(id: 41, slug: 'b', configuration: []);
        $schemaB->setAllOf(['40']);

        $this->schemaMapper->method('findAll')->willReturn([$schemaA]);
        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schemaA, $schemaB) {
                if ((string) $id === '40') {
                    return $schemaA;
                }

                if ((string) $id === '41') {
                    return $schemaB;
                }

                throw new \Exception('not found');
            }
        );

        // Must terminate (no infinite recursion) and still resolve A for Person.
        $result = $this->resolver->resolveSchemaByImplements(uri: self::PERSON_URI);
        $this->assertNotNull($result);
        $this->assertSame('a', $result->getSlug());

    }//end testCircularAllOfDoesNotLoop()
}//end class
