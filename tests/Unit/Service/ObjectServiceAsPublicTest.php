<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * HTTP-hardening tests for the `_rbac_as_public` flag on {@see ObjectService}.
 *
 * The flag MUST NOT be settable by external HTTP callers via the query dict —
 * only the trusted server-side method-parameter may enable it (RBA-PUBLIC-005).
 * Tests exercise `normalizeRbacAsPublicFlag` (the extracted helper backing the
 * strip pattern at the top of `searchObjectsPaginated`) so the security-property
 * has coverage without constructing the full ObjectService dependency graph.
 *
 * @spec openspec/changes/rbac-as-public-toggle/specs/rbac-as-public-toggle/spec.md#RBA-PUBLIC-005
 */
class ObjectServiceAsPublicTest extends TestCase
{
    private ObjectService $service;

    protected function setUp(): void
    {
        $this->service = new ObjectService(
            $this->createMock(DataManipulationHandler::class),
            $this->createMock(DeleteObject::class),
            $this->createMock(GetObject::class),
            $this->createMock(PerformanceHandler::class),
            $this->createMock(PermissionHandler::class),
            $this->createMock(RenderObject::class),
            $this->createMock(SaveObject::class),
            $this->createMock(SaveObjects::class),
            $this->createMock(SearchQueryHandler::class),
            $this->createMock(ValidateObject::class),
            $this->createMock(LockHandler::class),
            $this->createMock(AuditHandler::class),
            $this->createMock(RelationHandler::class),
            $this->createMock(MergeHandler::class),
            $this->createMock(FacetHandler::class),
            $this->createMock(MetadataHandler::class),
            $this->createMock(PerformanceOptimizationHandler::class),
            $this->createMock(QueryHandler::class),
            $this->createMock(RevertHandler::class),
            $this->createMock(UtilityHandler::class),
            $this->createMock(ValidationHandler::class),
            $this->createMock(CascadingHandler::class),
            $this->createMock(MigrationHandler::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(ViewMapper::class),
            $this->createMock(MagicMapper::class),
            $this->createMock(FileService::class),
            $this->createMock(IUserSession::class),
            $this->createMock(SearchTrailService::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(OrganisationService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(CacheHandler::class),
            $this->createMock(SettingsService::class),
            $this->createMock(DateTimeNormalizer::class),
            $this->createMock(IAppContainer::class)
        );
    }

    private function invokeNormalize(array $query, bool $_rbacAsPublic): array
    {
        $method = new ReflectionMethod(ObjectService::class, 'normalizeRbacAsPublicFlag');
        $method->setAccessible(true);
        return $method->invoke($this->service, $query, $_rbacAsPublic);
    }

    public function testClientSuppliedFlagIsStrippedWhenMethodParamFalse(): void
    {
        // Attacker case: client sends ?_rbac_as_public=true; method-param stays false.
        // Expected: flag is removed from the query — the strip fires.
        $query  = ['_rbac_as_public' => true, '_rbac' => true, 'foo' => 'bar'];
        $result = $this->invokeNormalize($query, false);

        $this->assertArrayNotHasKey(
            '_rbac_as_public',
            $result,
            'Client-supplied _rbac_as_public MUST be stripped when the trusted method-parameter is false'
        );
        // Other keys untouched.
        $this->assertSame(true, $result['_rbac']);
        $this->assertSame('bar', $result['foo']);
    }

    public function testTrustedMethodParamReSetsFlag(): void
    {
        // Server-side call: no client-supplied flag; method-param says true.
        // Expected: flag is present and set to true after normalisation.
        $query  = ['_rbac' => true];
        $result = $this->invokeNormalize($query, true);

        $this->assertArrayHasKey('_rbac_as_public', $result);
        $this->assertTrue($result['_rbac_as_public']);
    }

    public function testClientFlagIsStrippedEvenWhenMethodParamAlsoSetsIt(): void
    {
        // Belt-and-suspenders: client provides `false`, server-side sets `true`.
        // The strip fires first, then the server-side value wins.
        $query  = ['_rbac_as_public' => false];
        $result = $this->invokeNormalize($query, true);

        $this->assertTrue($result['_rbac_as_public'], 'Trusted method-parameter MUST override client value');
    }

    public function testClientFalseFlagIsStripped(): void
    {
        // Attacker case: client sends `_rbac_as_public=false` explicitly (garbage).
        // Method-param stays false. Expected: flag is absent (strip fires; no re-set).
        $query  = ['_rbac_as_public' => false];
        $result = $this->invokeNormalize($query, false);

        $this->assertArrayNotHasKey('_rbac_as_public', $result);
    }

    public function testEmptyQueryStaysEmptyWithMethodParamFalse(): void
    {
        $result = $this->invokeNormalize([], false);
        $this->assertSame([], $result);
    }

    public function testEmptyQueryGainsFlagWithMethodParamTrue(): void
    {
        $result = $this->invokeNormalize([], true);
        $this->assertSame(['_rbac_as_public' => true], $result);
    }

    public function testStringTruthyValueIsAlsoStripped(): void
    {
        // Client injection could send `?_rbac_as_public=true` which arrives as string "true".
        // The strip removes ANY value regardless of type — no type-coercion vulnerability.
        $query  = ['_rbac_as_public' => 'true'];
        $result = $this->invokeNormalize($query, false);

        $this->assertArrayNotHasKey('_rbac_as_public', $result);
    }
}
