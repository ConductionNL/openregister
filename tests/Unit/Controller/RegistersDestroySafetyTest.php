<?php

/**
 * RegistersController DELETE safety regression tests.
 *
 * Spec REQ (runtime-schema-api):
 *   "Same CRUD guarantees apply to /api/registers"
 *
 * Three scenarios are spec-mandated and covered here:
 *  - DELETE register with attached schemas-with-objects, no force → HTTP 409
 *    `{ error: 'register-has-objects', objectCount: N }`
 *  - DELETE register with ?force=true → 200, cache invalidated, mapper delete
 *  - DELETE unused register → 200 (regression baseline)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\UploadService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for RegistersController::destroy DELETE-safety guard.
 */
class RegistersDestroySafetyTest extends TestCase
{

    private RegistersController $controller;

    /** @var IRequest&MockObject */
    private IRequest $request;

    /** @var RegisterService&MockObject */
    private RegisterService $registerService;

    /** @var MagicMapper&MockObject */
    private MagicMapper $objectMapper;

    /** @var RegisterCacheHandler&MockObject */
    private RegisterCacheHandler $registerCacheHandler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;


    /**
     * Wire up RegistersController with every dependency mocked.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request              = $this->createMock(IRequest::class);
        $this->registerService      = $this->createMock(RegisterService::class);
        $this->objectMapper         = $this->createMock(MagicMapper::class);
        $this->registerCacheHandler = $this->createMock(RegisterCacheHandler::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->controller = new RegistersController(
            'openregister',
            $this->request,
            $this->registerService,
            $this->objectMapper,
            $this->createMock(UploadService::class),
            $this->logger,
            $this->createMock(IUserSession::class),
            $this->createMock(ConfigurationService::class),
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(ExportService::class),
            $this->createMock(ImportService::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(GitHubHandler::class),
            $this->createMock(IAppManager::class),
            $this->createMock(OasService::class),
            $this->registerCacheHandler
        );

    }//end setUp()


    /**
     * Build a Register entity with injected id + slug.
     */
    private function makeRegister(int $id, string $slug = 'test-register'): Register
    {
        $register = new Register();
        $register->setSlug($slug);
        $register->setTitle($slug);

        $ref  = new ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);

        return $register;

    }//end makeRegister()


    /**
     * REQ + SCENARIO: "Delete register with attached schemas-with-objects".
     *
     * MUST return HTTP 409 with `{ error: 'register-has-objects', objectCount: N }`
     * — and MUST NOT call RegisterService::delete. The register stays persisted.
     */
    public function testDestroyWithoutForceReturns409WhenObjectsExist(): void
    {
        $register = $this->makeRegister(7, 'openbuilt');

        $this->registerService
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo(7))
            ->willReturn($register);

        // 12 objects still reference schemas attached to this register.
        $this->objectMapper
            ->expects($this->once())
            ->method('getStatistics')
            ->with($this->equalTo(7), $this->equalTo(null))
            ->willReturn(['total' => 12]);

        $this->request
            ->method('getParam')
            ->with($this->equalTo('force'))
            ->willReturn(null);

        $this->registerService->expects($this->never())->method('delete');
        $this->registerCacheHandler->expects($this->never())->method('invalidate');

        $response = $this->controller->destroy(7);

        $this->assertSame(409, $response->getStatus());

        $data = $response->getData();
        $this->assertSame('register-has-objects', $data['error']);
        $this->assertSame(12, $data['objectCount']);

    }//end testDestroyWithoutForceReturns409WhenObjectsExist()


    /**
     * REQ + SCENARIO: Delete register with ?force=true.
     *
     * MUST proceed with delete (200), MUST invoke
     * RegisterCacheHandler::invalidate(id), MUST log a WARNING.
     */
    public function testDestroyWithForceTrueDeletesAndInvalidatesCache(): void
    {
        $register = $this->makeRegister(7, 'openbuilt');

        $this->registerService
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo(7))
            ->willReturn($register);

        $this->objectMapper
            ->expects($this->once())
            ->method('getStatistics')
            ->willReturn(['total' => 4]);

        $this->request
            ->method('getParam')
            ->with($this->equalTo('force'))
            ->willReturn('true');

        $this->registerService
            ->expects($this->once())
            ->method('delete')
            ->with($this->equalTo($register));

        $this->registerCacheHandler
            ->expects($this->once())
            ->method('invalidate')
            ->with($this->equalTo(7));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('Force-deleting register with attached objects'),
                $this->callback(function (array $ctx): bool {
                    return ($ctx['registerId'] ?? null) === 7
                        && ($ctx['objectCount'] ?? null) === 4;
                })
            );

        $response = $this->controller->destroy(7);

        $this->assertSame(200, $response->getStatus());

    }//end testDestroyWithForceTrueDeletesAndInvalidatesCache()


    /**
     * REQ + SCENARIO: Delete unused register (regression baseline).
     *
     * Establishes that the destroy path proceeds through delete + invalidate
     * when no objects reference the register, validating the 409/force paths
     * above are not vacuous.
     */
    public function testDestroyOnUnusedRegisterSucceeds(): void
    {
        $register = $this->makeRegister(13, 'empty-register');

        $this->registerService
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo(13))
            ->willReturn($register);

        $this->objectMapper
            ->expects($this->once())
            ->method('getStatistics')
            ->willReturn(['total' => 0]);

        $this->request
            ->method('getParam')
            ->with($this->equalTo('force'))
            ->willReturn(null);

        $this->registerService->expects($this->once())->method('delete');
        $this->registerCacheHandler->expects($this->once())->method('invalidate')->with($this->equalTo(13));

        $response = $this->controller->destroy(13);

        $this->assertSame(200, $response->getStatus());

    }//end testDestroyOnUnusedRegisterSucceeds()


}//end class
