<?php

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for the multitenancy interaction with `_rbac_as_public` (RBA-PUBLIC-002).
 *
 * `MagicOrganizationHandler::applyOrganizationFilter` reads the live user session directly
 * and does not honour the `_rbacAsPublic` flag. Leaving multitenancy enabled under
 * `_rbac_as_public: true` therefore filters an admin's rows to their active organisation
 * while an anonymous caller receives `1=0` — breaking the uniform-visibility contract
 * of SCH-PFTS-001. `MagicSearchHandler::resolveMultitenancyFlag` bypasses multitenancy
 * when the forced-anon endpoint is active, matching the pre-existing "public schema"
 * bypass behaviour.
 *
 * @spec openspec/changes/rbac-as-public-toggle/specs/rbac-as-public-toggle/spec.md#RBA-PUBLIC-002
 */
class MagicSearchHandlerAsPublicTest extends TestCase
{
    private MagicSearchHandler $handler;
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private MagicRbacHandler&MockObject $rbacHandler;
    private MagicOrganizationHandler&MockObject $organizationHandler;
    /** @var SchemaTypeConverter&MockObject */
    private $schemaTypeConverter;

    protected function setUp(): void
    {
        $this->db                  = $this->createMock(IDBConnection::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->rbacHandler         = $this->createMock(MagicRbacHandler::class);
        $this->organizationHandler = $this->createMock(MagicOrganizationHandler::class);
        $this->schemaTypeConverter = $this->createMock(SchemaTypeConverter::class);

        $this->handler = new MagicSearchHandler(
            $this->db,
            $this->logger,
            $this->rbacHandler,
            $this->organizationHandler,
            $this->schemaTypeConverter
        );
    }

    private function invokeResolveMultitenancyFlag(
        bool $_multitenancy,
        bool $multitenancyExplicit,
        Schema $schema,
        bool $_rbacAsPublic
    ): bool {
        $method = new ReflectionMethod(MagicSearchHandler::class, 'resolveMultitenancyFlag');
        $method->setAccessible(true);
        return $method->invoke(
            $this->handler,
            $_multitenancy,
            $multitenancyExplicit,
            $schema,
            $_rbacAsPublic
        );
    }

    private function nonPublicSchema(): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setAuthorization(['read' => ['authenticated']]);
        $schema->setTitle('Non-Public Schema');
        return $schema;
    }

    private function publicSchema(): Schema
    {
        $schema = new Schema();
        $schema->setId(2);
        $schema->setAuthorization(['read' => ['public']]);
        $schema->setTitle('Public Schema');
        return $schema;
    }

    public function testAsPublicBypassesMultitenancyOnNonPublicSchemaWhenNotExplicit(): void
    {
        // Forced-anon endpoint, non-public schema, multitenancy not explicitly requested.
        // Expected: bypass — otherwise admin's active-org filter would diverge from anon's 1=0.
        $result = $this->invokeResolveMultitenancyFlag(
            _multitenancy: true,
            multitenancyExplicit: false,
            schema: $this->nonPublicSchema(),
            _rbacAsPublic: true
        );

        $this->assertFalse(
            $result,
            '_rbacAsPublic=true MUST auto-bypass multitenancy so admin and anonymous callers see the same rows'
        );
    }

    public function testAsPublicRespectsExplicitMultitenancyRequest(): void
    {
        // Forced-anon endpoint, but caller explicitly asked for multitenancy.
        // Expected: keep multitenancy on — the explicit request wins.
        $result = $this->invokeResolveMultitenancyFlag(
            _multitenancy: true,
            multitenancyExplicit: true,
            schema: $this->nonPublicSchema(),
            _rbacAsPublic: true
        );

        $this->assertTrue(
            $result,
            'Explicit _multi=true MUST override the _rbacAsPublic auto-bypass'
        );
    }

    public function testDefaultBehaviorPreservedWhenAsPublicFalse(): void
    {
        // Backwards-compat: non-public schema, no forced-anon, no explicit → keep multitenancy on.
        $result = $this->invokeResolveMultitenancyFlag(
            _multitenancy: true,
            multitenancyExplicit: false,
            schema: $this->nonPublicSchema(),
            _rbacAsPublic: false
        );

        $this->assertTrue(
            $result,
            'Non-public schema without _rbacAsPublic MUST keep multitenancy on (backwards-compat)'
        );
    }

    public function testPublicSchemaBypassStillAppliesWhenAsPublicFalse(): void
    {
        // Existing behaviour: public-schema auto-bypass still works when _rbacAsPublic is off.
        $result = $this->invokeResolveMultitenancyFlag(
            _multitenancy: true,
            multitenancyExplicit: false,
            schema: $this->publicSchema(),
            _rbacAsPublic: false
        );

        $this->assertFalse(
            $result,
            'Pre-existing public-schema multitenancy bypass MUST continue to work'
        );
    }

    public function testMultitenancyOffStaysOffUnderAsPublic(): void
    {
        // If the caller already turned multitenancy off, the flag doesn't need to touch it.
        $result = $this->invokeResolveMultitenancyFlag(
            _multitenancy: false,
            multitenancyExplicit: false,
            schema: $this->nonPublicSchema(),
            _rbacAsPublic: true
        );

        $this->assertFalse($result);
    }
}
