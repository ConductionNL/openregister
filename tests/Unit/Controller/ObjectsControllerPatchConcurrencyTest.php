<?php

declare(strict_types=1);

/**
 * ObjectsController::patch() optimistic-concurrency (409) unit tests.
 *
 * `patch()` is a read-merge-write operation: two concurrent PATCHes can
 * silently clobber each other's untouched fields. A caller may pass the
 * `updated` timestamp it read as `_expectedUpdated` (If-Match semantics).
 * If the stored object's `updated` no longer matches, the write is rejected
 * with HTTP 409 instead of overwriting the newer version. Callers that omit
 * `_expectedUpdated` keep the previous (last-write-wins) behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the `_expectedUpdated` optimistic-concurrency guard on
 * ObjectsController::patch().
 */
class ObjectsControllerPatchConcurrencyTest extends TestCase
{
    private ObjectsController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private IAppManager&MockObject $appManager;
    private ContainerInterface&MockObject $container;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private ExportService&MockObject $exportService;
    private ImportService&MockObject $importService;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->config          = $this->createMock(IAppConfig::class);
        $this->appManager       = $this->createMock(IAppManager::class);
        $this->container        = $this->createMock(ContainerInterface::class);
        $this->registerMapper   = $this->createMock(RegisterMapper::class);
        $this->schemaMapper     = $this->createMock(SchemaMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
        $this->exportService    = $this->createMock(ExportService::class);
        $this->importService    = $this->createMock(ImportService::class);
        $this->webhookService   = $this->createMock(WebhookService::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        // resolveRegisterSchemaIds() reuses the entities resolved by
        // ObjectService::setRegister()/setSchema(); the ObjectService mock
        // returns null from the entity getters by default, so entities stay
        // null and the numeric ids passed in tests ('1' / '2') are used as-is.
        $this->controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $this->webhookService,
            $this->logger
        );
    }//end setUp()

    private function setupAdminUser(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
    }//end setupAdminUser()

    /**
     * Common object-service stubbing shared across the tests below: a stored
     * object that was `updated` at $storedUpdatedAt, and a patch payload of
     * `{title: 'Patched'}` plus (optionally) `_expectedUpdated`.
     */
    private function stubExistingObject(string $storedUpdatedAt): ObjectEntity
    {
        $existingObject = new ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old', 'status' => 'draft']);
        $existingObject->setUpdated(new \DateTime($storedUpdatedAt));

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        return $existingObject;
    }//end stubExistingObject()

    /**
     * getParam('_expectedUpdated') must be read from the raw request (not the
     * filtered patchData, since `_`-prefixed keys are stripped from that).
     */
    private function mockExpectedUpdatedParam(?string $value): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) use ($value) {
                if ($key === '_expectedUpdated') {
                    return $value;
                }

                return $default;
            });
    }//end mockExpectedUpdatedParam()

    /**
     * Stale `_expectedUpdated` (does not match the stored `updated`) -> 409,
     * with the conflict payload describing both timestamps, and saveObject()
     * must never be called (the write is rejected, not merged).
     */
    public function testPatchReturns409WhenExpectedUpdatedIsStale(): void
    {
        $this->setupAdminUser();

        $storedUpdatedAt = '2026-07-01T10:00:00+00:00';
        $this->stubExistingObject($storedUpdatedAt);

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->mockExpectedUpdatedParam('2026-06-30T09:00:00+00:00');

        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(409, $result->getStatus());
        $body = $result->getData();
        $this->assertSame('2026-06-30T09:00:00+00:00', $body['expectedUpdated']);
        $this->assertSame($storedUpdatedAt, $body['currentUpdated']);
        $this->assertStringContainsString('Conflict', $body['error']);
    }//end testPatchReturns409WhenExpectedUpdatedIsStale()

    /**
     * Matching `_expectedUpdated` -> the write proceeds normally (200).
     */
    public function testPatchSucceedsWhenExpectedUpdatedMatches(): void
    {
        $this->setupAdminUser();

        $storedUpdatedAt = '2026-07-01T10:00:00+00:00';
        $this->stubExistingObject($storedUpdatedAt);

        $patchedObject = new ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'Patched', 'status' => 'draft']);

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->mockExpectedUpdatedParam($storedUpdatedAt);

        $this->objectService->expects($this->once())->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }//end testPatchSucceedsWhenExpectedUpdatedMatches()

    /**
     * Omitted `_expectedUpdated` -> opt-in guard is skipped entirely, the
     * write proceeds regardless of the stored `updated` value (legacy
     * last-write-wins behaviour is preserved for callers that don't send it).
     */
    public function testPatchSucceedsWhenExpectedUpdatedIsOmitted(): void
    {
        $this->setupAdminUser();

        $storedUpdatedAt = '2026-07-01T10:00:00+00:00';
        $this->stubExistingObject($storedUpdatedAt);

        $patchedObject = new ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'Patched', 'status' => 'draft']);

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->mockExpectedUpdatedParam(null);

        $this->objectService->expects($this->once())->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }//end testPatchSucceedsWhenExpectedUpdatedIsOmitted()
}//end class
