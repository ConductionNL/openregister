<?php

declare(strict_types=1);

/*
 * SaveObject circular-reference detection.
 *
 * Closes the `reference-existence-validation` spec's
 * "Circular reference chains detected during validation"
 * requirement.
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
use OCA\OpenRegister\Exception\CircularReferenceException;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * Unit tests for SaveObject's per-save call stack + circular-reference
 * detection. Exercises the helpers via reflection because they are
 * private (the public surface is `saveObject()`, but the recursive
 * cascade required to drive cycle detection involves the entire
 * SaveObject DI graph + a real DB).
 */
class SaveObjectCircularReferenceTest extends TestCase
{


    private function buildHandler(
        ?MagicMapper $unifiedObjectMapper=null,
        ?SchemaMapper $schemaMapper=null
    ): SaveObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('plain-user');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new SaveObject(
            objectEntityMapper: $this->createMock(MagicMapper::class),
            unifiedObjectMapper: $unifiedObjectMapper ?? $this->createMock(MagicMapper::class),
            metaHydrationHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler::class),
            filePropertyHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler::class),
            linkedEntityHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
            userSession: $session,
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
            groupManager: $this->createMock(IGroupManager::class),
            appConfig: $this->createMock(IAppConfig::class),
            eventDispatcher: $this->createMock(IEventDispatcher::class),
        );
    }


    private function invoke(SaveObject $handler, string $methodName, array $args): mixed
    {
        $reflection = new ReflectionMethod(SaveObject::class, $methodName);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($handler, array_values($args));
    }


    private function readStack(SaveObject $handler): array
    {
        $reflection = new ReflectionProperty(SaveObject::class, 'saveCallStack');
        $reflection->setAccessible(true);
        return (array) $reflection->getValue($handler);
    }


    private function buildSchemaWithReference(): Schema
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getProperties'])
            ->getMock();
        $schema->setId(1);
        $schema->setSlug('parent');
        $schema->method('getProperties')->willReturn(
            [
                'parent' => [
                    'type'              => 'string',
                    '$ref'              => '#/components/schemas/parent',
                    'validateReference' => true,
                ],
            ]
        );
        return $schema;
    }


    public function testPushAndPopMaintainsBalancedStack(): void
    {
        $handler = $this->buildHandler();

        $key = $this->invoke($handler, 'pushSaveCallFrame', [
            'schemaSlug' => 'organisations',
            'uuid'       => 'aaa-111',
            'register'   => 'r1',
        ]);
        $this->assertSame('organisations:aaa-111', $key);
        $this->assertCount(1, $this->readStack($handler));

        $this->invoke($handler, 'popSaveCallFrame', [$key]);
        $this->assertCount(0, $this->readStack($handler));
    }


    public function testEmptyUuidPushReturnsNull(): void
    {
        $handler = $this->buildHandler();
        $key     = $this->invoke($handler, 'pushSaveCallFrame', [
            'schemaSlug' => 'organisations',
            'uuid'       => '',
            'register'   => null,
        ]);
        $this->assertNull($key);
        $this->assertCount(0, $this->readStack($handler));
    }


    public function testDuplicatePushIsRejectedWithoutCorruption(): void
    {
        $handler = $this->buildHandler();

        $first  = $this->invoke($handler, 'pushSaveCallFrame', [
            'schemaSlug' => 'organisations',
            'uuid'       => 'aaa-111',
            'register'   => 'r1',
        ]);
        // Second push of the same (schema, uuid) is a no-op.
        $second = $this->invoke($handler, 'pushSaveCallFrame', [
            'schemaSlug' => 'organisations',
            'uuid'       => 'aaa-111',
            'register'   => 'r1',
        ]);

        $this->assertSame('organisations:aaa-111', $first);
        $this->assertNull($second);
        $this->assertCount(1, $this->readStack($handler));

        $this->invoke($handler, 'popSaveCallFrame', [$first]);
        $this->assertCount(0, $this->readStack($handler));
    }


    public function testDetectCircularReferenceReturnsCycleWhenUuidOnStack(): void
    {
        $handler = $this->buildHandler();

        $key1 = $this->invoke($handler, 'pushSaveCallFrame', [
            'schemaSlug' => 'parent',
            'uuid'       => 'p-1',
            'register'   => 'r1',
        ]);
        $key2 = $this->invoke($handler, 'pushSaveCallFrame', [
            'schemaSlug' => 'child',
            'uuid'       => 'c-1',
            'register'   => 'r1',
        ]);

        // p-1 is on the stack — re-encountering it under a different
        // schema must still trigger the cycle (different schema, same
        // UUID = back-reference).
        $cycle = $this->invoke($handler, 'detectCircularReference', ['p-1']);
        $this->assertIsArray($cycle);
        $this->assertCount(2, $cycle);
        $this->assertSame('p-1', $cycle[0]['uuid']);
        $this->assertSame('c-1', $cycle[1]['uuid']);

        // A UUID not on the stack returns null.
        $miss = $this->invoke($handler, 'detectCircularReference', ['stranger']);
        $this->assertNull($miss);

        $this->invoke($handler, 'popSaveCallFrame', [$key2]);
        $this->invoke($handler, 'popSaveCallFrame', [$key1]);
    }


    public function testValidateReferenceExistsThrowsCircularReferenceException(): void
    {
        // unifiedObjectMapper->find() must NOT be called — the cycle
        // short-circuit fires before the existence check.
        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->expects($this->never())->method('find');

        $handler = $this->buildHandler(
            unifiedObjectMapper: $unifiedMapper,
        );

        // Push p-1 onto the stack to simulate "we are already saving p-1".
        $key = $this->invoke($handler, 'pushSaveCallFrame', [
            'schemaSlug' => 'parent',
            'uuid'       => 'p-1',
            'register'   => 'r1',
        ]);

        try {
            $this->invoke(
                $handler,
                'validateReferenceExists',
                [
                    'propertyName' => 'parentRef',
                    'uuid'         => 'p-1',
                    'schemaRef'    => '#/components/schemas/parent',
                    'register'     => 'r1',
                ]
            );
            $this->fail('Expected CircularReferenceException');
        } catch (CircularReferenceException $e) {
            $this->assertSame('p-1', $e->getReferencedUuid());
            $this->assertSame('#/components/schemas/parent', $e->getTargetSchemaSlug());
            $this->assertNotEmpty($e->getCycle());
            $this->assertInstanceOf(\OCA\OpenRegister\Exception\ValidationException::class, $e);
            $this->assertNotInstanceOf(ReferenceValidationException::class, $e);
        } finally {
            $this->invoke($handler, 'popSaveCallFrame', [$key]);
        }
    }


    public function testCircularExceptionIsValidationButNotReferenceValidationSubclass(): void
    {
        // Make sure the catch block in `validateReferences()` that
        // catches `ReferenceValidationException` does NOT also catch
        // CircularReferenceException — the cycle must always propagate
        // even in warn-mode because a cycle would cause infinite
        // recursion if swallowed.
        $exception = new CircularReferenceException(
            referencedUuid: 'p-1',
            targetSchemaSlug: 'parent',
            cycle: [['schema' => 'parent', 'uuid' => 'p-1', 'register' => 'r1']],
        );
        $this->assertInstanceOf(\OCA\OpenRegister\Exception\ValidationException::class, $exception);
        $this->assertNotInstanceOf(ReferenceValidationException::class, $exception);
    }
}
