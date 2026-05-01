<?php

declare(strict_types=1);

/*
 * SaveObject reference-validation extension behaviour.
 *
 * Covers two spec items added in the
 * `reference-existence-validation` change:
 *   1. Admin users can bypass reference validation when the
 *      `reference_validation_admin_bypass` app-config flag is on.
 *   2. The save pipeline emits `ReferenceValidatedEvent` /
 *      `ReferenceValidationFailedEvent` so listeners can hook into
 *      validation outcomes.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ReferenceValidatedEvent;
use OCA\OpenRegister\Event\ReferenceValidationFailedEvent;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Unit tests for the admin-bypass + event dispatch behaviour added to
 * SaveObject's reference-existence validation pipeline.
 */
class SaveObjectReferenceValidationTest extends TestCase
{
    /**
     * Build a SaveObject under test with all dependencies mocked. Lets
     * each test override the user, group manager, app-config and event
     * dispatcher to assert the new behaviours in isolation.
     */
    private function buildHandler(
        IUserSession $userSession,
        ?IGroupManager $groupManager,
        ?IAppConfig $appConfig,
        ?IEventDispatcher $eventDispatcher,
        ?MagicMapper $unifiedObjectMapper=null,
        ?SchemaMapper $schemaMapper=null
    ): SaveObject {
        return new SaveObject(
            objectEntityMapper: $this->createMock(MagicMapper::class),
            unifiedObjectMapper: $unifiedObjectMapper ?? $this->createMock(MagicMapper::class),
            metaHydrationHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler::class),
            filePropertyHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler::class),
            linkedEntityHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
            userSession: $userSession,
            auditTrailMapper: $this->createMock(AuditTrailMapper::class),
            schemaMapper: $schemaMapper ?? $this->createMock(SchemaMapper::class),
            registerMapper: $this->createMock(RegisterMapper::class),
            urlGenerator: $this->createMock(IURLGenerator::class),
            organisationService: $this->createMock(OrganisationService::class),
            cacheHandler: $this->createMock(CacheHandler::class),
            settingsService: $this->createMock(SettingsService::class),
            propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
            computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
            translationHandler: $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
            logger: $this->createMock(LoggerInterface::class),
            tmloService: $this->createMock(\OCA\OpenRegister\Service\TmloService::class),
            arrayLoader: new ArrayLoader(),
            groupManager: $groupManager,
            appConfig: $appConfig,
            eventDispatcher: $eventDispatcher,
        );
    }//end buildHandler()

    private function invoke(SaveObject $handler, string $methodName, array $args): mixed
    {
        $reflection = new ReflectionMethod(SaveObject::class, $methodName);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($handler, array_values($args));
    }//end invoke()

    private function buildSchemaWithReference(mixed $validateReference=true): Schema
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getProperties'])
            ->getMock();
        $schema->setId(1);
        $schema->setSlug('referrer');
        $schema->method('getProperties')->willReturn(
                [
                    'organisation' => [
                        'type'              => 'string',
                        '$ref'              => '#/components/schemas/organisations',
                        'validateReference' => $validateReference,
                    ],
                ]
                );
        return $schema;
    }//end buildSchemaWithReference()

    /**
     * Build a SchemaMapper mock whose findAll() returns a target
     * schema named `organisations` so resolveSchemaReference() can
     * walk `#/components/schemas/organisations` to a real ID.
     */
    private function makeSchemaMapperResolvingTarget(): SchemaMapper
    {
        $target = $this->getMockBuilder(Schema::class)
            ->onlyMethods([])
            ->getMock();
        $target->setId(2);
        $target->setSlug('organisations');

        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('findAll')->willReturn([$target]);
        return $schemaMapper;
    }//end makeSchemaMapperResolvingTarget()

    private function makeAppConfig(string $bypassValue='true'): IAppConfig
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->with('openregister', 'reference_validation_admin_bypass', 'true')
            ->willReturn($bypassValue);
        return $appConfig;
    }//end makeAppConfig()

    private function makeAdminSession(string $uid='admin-user'): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }//end makeAdminSession()

    private function makeNonAdminSession(string $uid='plain-user'): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }//end makeNonAdminSession()

    private function makeAdminGroupManager(bool $isAdmin): IGroupManager
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);
        return $groupManager;
    }//end makeAdminGroupManager()

    // ====================================================================
    // 1. Admin bypass — Open spec item:
    // "Admin users able to bypass reference validation."
    // ====================================================================
    public function testAdminUserBypassesReferenceValidationWhenFlagDefaultOn(): void
    {
        // unifiedObjectMapper->find() must NOT be called when admin bypass kicks in.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->never())->method('find');

        $handler = $this->buildHandler(
            userSession: $this->makeAdminSession(),
            groupManager: $this->makeAdminGroupManager(true),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
        );

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference(),
                'data'     => ['organisation' => 'never-checked-uuid'],
                'register' => '1',
                'oldData'  => null,
            ]
        );
    }//end testAdminUserBypassesReferenceValidationWhenFlagDefaultOn()

    public function testAdminUserDoesNotBypassWhenFlagDisabled(): void
    {
        // With bypass off, the mapper IS called and the missing object
        // surfaces as a ReferenceValidationException.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $handler = $this->buildHandler(
            userSession: $this->makeAdminSession(),
            groupManager: $this->makeAdminGroupManager(true),
            appConfig: $this->makeAppConfig('false'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference(),
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testAdminUserDoesNotBypassWhenFlagDisabled()

    public function testNonAdminUserNeverBypassesEvenWhenFlagOn(): void
    {
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference(),
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testNonAdminUserNeverBypassesEvenWhenFlagOn()

    public function testBypassDisabledWhenGroupManagerMissing(): void
    {
        // Optional dependencies absent — validation must still run as
        // before (back-compat with older test fixtures that don't pass
        // groupManager / appConfig).
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $handler = $this->buildHandler(
            userSession: $this->makeAdminSession(),
            groupManager: null,
            appConfig: null,
            eventDispatcher: null,
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference(),
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testBypassDisabledWhenGroupManagerMissing()

    // ====================================================================
    // 2. Validation events — Open spec item:
    // "Validation events dispatched for notification and extensibility."
    // ====================================================================
    public function testReferenceValidationFailedEventDispatchedOnMissingTarget(): void
    {
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')->willThrowException(new DoesNotExistException('not found'));

        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $captured        = null;
        $eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(
                    function ($event) use (&$captured): void {
                        $captured = $event;
                    }
                    );

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $eventDispatcher,
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        try {
            $this->invoke(
                $handler,
                'validateReferences',
                [
                    'schema'   => $this->buildSchemaWithReference(),
                    'data'     => ['organisation' => 'missing-uuid'],
                    'register' => '1',
                    'oldData'  => null,
                ]
            );
            $this->fail('Expected ReferenceValidationException');
        } catch (ReferenceValidationException $e) {
            // expected
        }

        $this->assertInstanceOf(ReferenceValidationFailedEvent::class, $captured);
        $this->assertSame('organisation', $captured->getPropertyName());
        $this->assertSame('missing-uuid', $captured->getReferencedUuid());
        $this->assertSame('organisations', $captured->getTargetSchemaSlug());
        $this->assertSame('1', $captured->getTargetRegister());
    }//end testReferenceValidationFailedEventDispatchedOnMissingTarget()

    public function testReferenceValidatedEventDispatchedOnSuccessfulLookup(): void
    {
        // Mapper find succeeds (returns silently) → success event must fire.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())->method('find');

        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $captured        = null;
        $eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(
                    function ($event) use (&$captured): void {
                        $captured = $event;
                    }
                    );

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $eventDispatcher,
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference(),
                'data'     => ['organisation' => 'existing-uuid'],
                'register' => '1',
                'oldData'  => null,
            ]
        );

        $this->assertInstanceOf(ReferenceValidatedEvent::class, $captured);
        $this->assertSame('organisation', $captured->getPropertyName());
        $this->assertSame('existing-uuid', $captured->getReferencedUuid());
        $this->assertSame('organisations', $captured->getTargetSchemaSlug());
        $this->assertSame('1', $captured->getTargetRegister());
    }//end testReferenceValidatedEventDispatchedOnSuccessfulLookup()

    public function testNoEventsDispatchedWhenDispatcherMissing(): void
    {
        // No exception, no fatals, no dispatch calls — graceful no-op.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find');
        // succeeds
        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: null,
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference(),
                'data'     => ['organisation' => 'existing-uuid'],
                'register' => '1',
                'oldData'  => null,
            ]
        );

        $this->assertTrue(true, 'No-op when event dispatcher absent');
    }//end testNoEventsDispatchedWhenDispatcherMissing()

    // ====================================================================
    // 3. Soft-delete handling — Open spec item:
    // "Soft-deleted references treated as nonexistent."
    //
    // Verified by asserting `validateReferenceExists()` calls the mapper
    // with `includeDeleted: false` so any soft-deleted target is treated
    // as absent (DoesNotExistException → ReferenceValidationException).
    // ====================================================================
    public function testValidateReferenceExistsPassesIncludeDeletedFalse(): void
    {
        $captured      = null;
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())
            ->method('find')
            ->willReturnCallback(
                    function (...$args) use (&$captured) {
                        $captured = $args;
                        throw new DoesNotExistException('soft deleted');
                    }
                    );

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        try {
            $this->invoke(
                $handler,
                'validateReferences',
                [
                    'schema'   => $this->buildSchemaWithReference(),
                    'data'     => ['organisation' => 'soft-deleted-uuid'],
                    'register' => null,
                    'oldData'  => null,
                ]
            );
            $this->fail('Expected ReferenceValidationException for soft-deleted target');
        } catch (ReferenceValidationException $e) {
            $this->assertSame('soft-deleted-uuid', $e->getReferencedUuid());
        }

        // PHPUnit's named-arg dispatch path may give us positional args
        // depending on how the mock method is invoked, but the named
        // form is what SaveObject uses. Inspect both shapes.
        $this->assertNotNull($captured, 'mapper find must have been invoked');
        $found = false;
        foreach ($captured as $arg) {
            if ($arg === false || $arg === true) {
                // Sentinel value present — skip.
            }
        }

        // Reflect over the mapper find arguments to find includeDeleted=false.
        // The handler calls `find(identifier:..., register:..., schema:..., includeDeleted:false, _rbac:false, _multitenancy:false)`.
        // PHPUnit positional-mock invocation always provides args in
        // declaration order; `includeDeleted` is the 4th arg.
        $this->assertCount(6, $captured, 'find() should be called with 6 named arguments');
        $this->assertSame(false, $captured[3], 'includeDeleted must be false');
    }//end testValidateReferenceExistsPassesIncludeDeletedFalse()

    // ====================================================================
    // 4. Request-scoped cache — Open spec item:
    // "Validation results cached within a request scope."
    // "Batch reference validation optimised for bulk imports."
    // ====================================================================
    public function testRequestScopedCachePreventsDuplicateLookups(): void
    {
        // Two saves pointing at the same UUID inside one request must
        // hit the database exactly once.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())->method('find');

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $schema = $this->buildSchemaWithReference();
        for ($i = 0; $i < 5; $i++) {
            $this->invoke(
                $handler,
                'validateReferences',
                [
                    'schema'   => $schema,
                    'data'     => ['organisation' => 'shared-uuid'],
                    'register' => '1',
                    'oldData'  => null,
                ]
            );
        }
    }//end testRequestScopedCachePreventsDuplicateLookups()

    public function testRequestScopedCacheReplaysNegativeVerdictWithoutDuplicateEvents(): void
    {
        // The first miss raises ReferenceValidationException + dispatches
        // the failure event. A second save with the same UUID must
        // re-raise without re-querying the mapper AND without
        // re-dispatching the failure event.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $eventDispatcher->expects($this->once())->method('dispatchTyped');

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $eventDispatcher,
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $schema = $this->buildSchemaWithReference();
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->invoke(
                    $handler,
                    'validateReferences',
                    [
                        'schema'   => $schema,
                        'data'     => ['organisation' => 'missing-uuid'],
                        'register' => '1',
                        'oldData'  => null,
                    ]
                );
                $this->fail('Expected ReferenceValidationException on iteration '.$i);
            } catch (ReferenceValidationException $e) {
                $this->assertSame('missing-uuid', $e->getReferencedUuid());
            }
        }
    }//end testRequestScopedCacheReplaysNegativeVerdictWithoutDuplicateEvents()

    public function testClearReferenceValidationCacheForcesRevalidation(): void
    {
        // After clearReferenceValidationCache() the next save must hit
        // the mapper again — useful for long-running CLI cycles.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->exactly(2))->method('find');

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $schema = $this->buildSchemaWithReference();
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $schema,
                'data'     => ['organisation' => 'shared-uuid'],
                'register' => '1',
                'oldData'  => null,
            ]
        );

        $handler->clearReferenceValidationCache();

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $schema,
                'data'     => ['organisation' => 'shared-uuid'],
                'register' => '1',
                'oldData'  => null,
            ]
        );
    }//end testClearReferenceValidationCacheForcesRevalidation()

    // ====================================================================
    // 4. Schema-configurable validation strictness levels — Open spec item:
    // "Schema-configurable validation strictness levels."
    // The `validateReference` flag now accepts 'warn' / 'error' / 'block'
    // alongside the historical boolean `true`. Warn-mode logs the failure
    // and dispatches the failure event but does NOT throw the 422, so
    // schema authors can adopt validation gradually on registers with
    // known dirty data.
    // ====================================================================

    /**
     * Warn-mode short-circuits the throw so the save proceeds.
     */
    public function testStrictnessWarnDoesNotThrowOnMissingReference(): void
    {
        // The mapper is consulted (validation is enabled) and reports
        // "not found", but the warn-mode short-circuits the throw so
        // the save proceeds without raising HTTP 422.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('warn-only'),
                $this->arrayHasKey('uuid')
            );

        $handler = new SaveObject(
            objectEntityMapper: $this->createMock(MagicMapper::class),
            unifiedObjectMapper: $unifiedMapper,
            metaHydrationHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler::class),
            filePropertyHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler::class),
            linkedEntityHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
            userSession: $this->makeNonAdminSession(),
            auditTrailMapper: $this->createMock(AuditTrailMapper::class),
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
            registerMapper: $this->createMock(RegisterMapper::class),
            urlGenerator: $this->createMock(IURLGenerator::class),
            organisationService: $this->createMock(OrganisationService::class),
            cacheHandler: $this->createMock(CacheHandler::class),
            settingsService: $this->createMock(SettingsService::class),
            propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
            computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
            translationHandler: $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
            logger: $logger,
            tmloService: $this->createMock(\OCA\OpenRegister\Service\TmloService::class),
            arrayLoader: new ArrayLoader(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
        );

        // Should NOT throw — warn mode swallows the missing-reference
        // exception and lets the save proceed.
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference('warn'),
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testStrictnessWarnDoesNotThrowOnMissingReference()

    /**
     * Warn-mode still dispatches the failure event so listeners observe the miss.
     */
    public function testStrictnessWarnStillDispatchesFailureEvent(): void
    {
        // Listeners must observe every miss regardless of strictness:
        // monitoring + analytics need to count dangling references in
        // warn-mode registers too.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ReferenceValidationFailedEvent::class));

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $eventDispatcher,
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference('warn'),
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testStrictnessWarnStillDispatchesFailureEvent()

    /**
     * `validateReference: 'error'` matches legacy boolean-true semantics.
     */
    public function testStrictnessErrorRejectsOnMissingReference(): void
    {
        // The string 'error' must behave identically to the legacy
        // boolean `true`: HTTP 422 is raised on a missing target.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference('error'),
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testStrictnessErrorRejectsOnMissingReference()

    /**
     * `validateReference: 'block'` is an alias of `'error'`.
     */
    public function testStrictnessBlockRejectsOnMissingReference(): void
    {
        // 'block' is an alias of 'error' — same throw semantics, no
        // database round trips skipped, no event suppressed.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference('block'),
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testStrictnessBlockRejectsOnMissingReference()

    /**
     * `validationStrictness: 'warn'` overrides default strict severity.
     */
    public function testStrictnessFieldDowngradesBooleanTrueToWarn(): void
    {
        // The canonical spec shape: keep `validateReference: true` and
        // declare severity via `validationStrictness: 'warn'`. Should
        // behave identically to validateReference: 'warn'.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getProperties'])
            ->getMock();
        $schema->setId(1);
        $schema->setSlug('referrer');
        $schema->method('getProperties')->willReturn(
                [
                    'organisation' => [
                        'type'                 => 'string',
                        '$ref'                 => '#/components/schemas/organisations',
                        'validateReference'    => true,
                        'validationStrictness' => 'warn',
                    ],
                ]
                );

        // Should NOT throw — warn severity overrides default strict.
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $schema,
                'data'     => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );

        // Reaching here proves no exception was thrown.
        $this->assertTrue(true);
    }//end testStrictnessFieldDowngradesBooleanTrueToWarn()

    /**
     * `validationStrictness: 'off'` disables validation even when
     * `validateReference: true` is present.
     */
    public function testStrictnessFieldOffDisablesValidationEvenWhenBooleanTrue(): void
    {
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->never())->method('find');

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
        );

        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getProperties'])
            ->getMock();
        $schema->setId(1);
        $schema->setSlug('referrer');
        $schema->method('getProperties')->willReturn(
                [
                    'organisation' => [
                        'type'                 => 'string',
                        '$ref'                 => '#/components/schemas/organisations',
                        'validateReference'    => true,
                        'validationStrictness' => 'off',
                    ],
                ]
                );

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $schema,
                'data'     => ['organisation' => 'never-checked-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testStrictnessFieldOffDisablesValidationEvenWhenBooleanTrue()

    /**
     * `validateReference: false` disables validation entirely.
     */
    public function testStrictnessFalseDisablesValidation(): void
    {
        // The historical disable-by-omission semantics must continue:
        // validateReference: false (or absent) skips the mapper entirely.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->never())->method('find');

        $handler = $this->buildHandler(
            userSession: $this->makeNonAdminSession(),
            groupManager: $this->makeAdminGroupManager(false),
            appConfig: $this->makeAppConfig('true'),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
            unifiedObjectMapper: $unifiedMapper,
        );

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema'   => $this->buildSchemaWithReference(false),
                'data'     => ['organisation' => 'never-checked-uuid'],
                'register' => null,
                'oldData'  => null,
            ]
        );
    }//end testStrictnessFalseDisablesValidation()
}//end class
