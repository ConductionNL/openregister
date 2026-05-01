<?php

declare(strict_types=1);

/**
 * SaveObject reference-validation extension behaviour.
 *
 * Covers the spec item added in the
 * `reference-existence-validation` change:
 *   Admin users can bypass reference validation when the
 *   `reference_validation_admin_bypass` app-config flag is on.
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
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
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
 * Unit tests for the admin-bypass behaviour added to SaveObject's
 * reference-existence validation pipeline.
 */
class SaveObjectReferenceValidationTest extends TestCase
{
    /**
     * Build a SaveObject under test with all dependencies mocked. Lets
     * each test override the user, group manager, and app-config to
     * assert the new behaviours in isolation.
     */
    private function buildHandler(
        IUserSession $userSession,
        ?IGroupManager $groupManager,
        ?IAppConfig $appConfig,
        ?MagicMapper $unifiedObjectMapper = null,
        ?SchemaMapper $schemaMapper = null
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
        );
    }

    private function invoke(SaveObject $handler, string $methodName, array $args): mixed
    {
        $reflection = new ReflectionMethod(SaveObject::class, $methodName);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($handler, array_values($args));
    }

    private function buildSchemaWithReference(): Schema
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getProperties'])
            ->getMock();
        $schema->setId(1);
        $schema->setSlug('referrer');
        $schema->method('getProperties')->willReturn([
            'organisation' => [
                'type' => 'string',
                '$ref' => '#/components/schemas/organisations',
                'validateReference' => true,
            ],
        ]);
        return $schema;
    }

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
    }

    private function makeAppConfig(string $bypassValue = 'true'): IAppConfig
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->with('openregister', 'reference_validation_admin_bypass', 'true')
            ->willReturn($bypassValue);
        return $appConfig;
    }

    private function makeAdminSession(string $uid = 'admin-user'): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function makeNonAdminSession(string $uid = 'plain-user'): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function makeAdminGroupManager(bool $isAdmin): IGroupManager
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);
        return $groupManager;
    }

    // ====================================================================
    // Admin bypass — Open spec item:
    //    "Admin users able to bypass reference validation."
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
            unifiedObjectMapper: $unifiedMapper,
        );

        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema' => $this->buildSchemaWithReference(),
                'data' => ['organisation' => 'never-checked-uuid'],
                'register' => '1',
                'oldData' => null,
            ]
        );
    }

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
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema' => $this->buildSchemaWithReference(),
                'data' => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData' => null,
            ]
        );
    }

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
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema' => $this->buildSchemaWithReference(),
                'data' => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData' => null,
            ]
        );
    }

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
            unifiedObjectMapper: $unifiedMapper,
            schemaMapper: $this->makeSchemaMapperResolvingTarget(),
        );

        $this->expectException(ReferenceValidationException::class);
        $this->invoke(
            $handler,
            'validateReferences',
            [
                'schema' => $this->buildSchemaWithReference(),
                'data' => ['organisation' => 'missing-uuid'],
                'register' => null,
                'oldData' => null,
            ]
        );
    }
}
