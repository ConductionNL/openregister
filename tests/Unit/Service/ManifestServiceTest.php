<?php

/**
 * Unit tests for ManifestService.
 *
 * Covers the four runtime.user scenarios:
 *   1. Manifest with no currentUserSchema → returned unchanged.
 *   2. Anonymous request                   → runtime.user = null.
 *   3. Logged-in user, no profile object  → fallback { id, roles: ["learner"] }.
 *   4. Logged-in user, profile found      → full context with calculated fields.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\ManifestService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit test suite for ManifestService.
 */
class ManifestServiceTest extends TestCase
{
    /** @var ObjectService&MockObject */
    private ObjectService $objectService;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var CalculationEvaluator&MockObject */
    private CalculationEvaluator $evaluator;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private ManifestService $service;

    /**
     * Set up mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->evaluator     = $this->createMock(CalculationEvaluator::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->service = new ManifestService(
            $this->objectService,
            $this->schemaMapper,
            $this->evaluator,
            $this->userSession,
            $this->logger
        );
    }//end setUp()

    // -----------------------------------------------------------------------
    // 1. No currentUserSchema declared
    // -----------------------------------------------------------------------

    /**
     * Manifests without currentUserSchema must be returned exactly as provided.
     *
     * @return void
     */
    public function testManifestWithoutCurrentUserSchemaIsUnchanged(): void
    {
        $manifest = ['version' => '1.0.0', 'pages' => []];

        $result = $this->service->getEnrichedManifest($manifest);

        $this->assertSame($manifest, $result);
    }//end testManifestWithoutCurrentUserSchemaIsUnchanged()

    /**
     * Manifest with an empty currentUserSchema string must also be unchanged.
     *
     * @return void
     */
    public function testManifestWithEmptyCurrentUserSchemaIsUnchanged(): void
    {
        $manifest = ['currentUserSchema' => '', 'pages' => []];

        $result = $this->service->getEnrichedManifest($manifest);

        $this->assertSame($manifest, $result);
    }//end testManifestWithEmptyCurrentUserSchemaIsUnchanged()

    // -----------------------------------------------------------------------
    // 2. Anonymous request → runtime.user = null
    // -----------------------------------------------------------------------

    /**
     * An anonymous (unauthenticated) request must yield runtime.user = null.
     *
     * @return void
     */
    public function testAnonymousRequestYieldsNullUserContext(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $manifest = ['currentUserSchema' => 'user-profile', 'pages' => []];
        $result   = $this->service->getEnrichedManifest($manifest);

        $this->assertArrayHasKey('runtime', $result);
        $this->assertNull($result['runtime']['user']);
    }//end testAnonymousRequestYieldsNullUserContext()

    // -----------------------------------------------------------------------
    // 3. Logged-in user but no profile object → fallback
    // -----------------------------------------------------------------------

    /**
     * When the user is authenticated but has no profile object, the service
     * must return the minimal fallback { id, roles: ["learner"] }.
     *
     * @return void
     */
    public function testUserWithoutProfileGetsFallback(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $this->userSession->method('getUser')->willReturn($user);

        // Schema found so the findAll call is reached.
        $schema = $this->createMock(Schema::class);
        $this->schemaMapper
            ->method('findBySlug')
            ->willReturn([$schema]);

        // No matching objects.
        $this->objectService
            ->method('findAll')
            ->willReturn([]);

        $manifest = ['currentUserSchema' => 'user-profile', 'pages' => []];
        $result   = $this->service->getEnrichedManifest($manifest);

        $this->assertArrayHasKey('runtime', $result);
        $userCtx = $result['runtime']['user'];
        $this->assertIsArray($userCtx);
        $this->assertSame('alice', $userCtx['id']);
        $this->assertSame(['learner'], $userCtx['roles']);
    }//end testUserWithoutProfileGetsFallback()

    /**
     * When the schema is not found, the fallback is still returned gracefully.
     *
     * @return void
     */
    public function testUserWithUnknownSchemaGetsFallback(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');

        $this->userSession->method('getUser')->willReturn($user);

        // Schema not found.
        $this->schemaMapper
            ->method('findBySlug')
            ->willReturn([]);

        $manifest = ['currentUserSchema' => 'nonexistent-schema', 'pages' => []];
        $result   = $this->service->getEnrichedManifest($manifest);

        $this->assertArrayHasKey('runtime', $result);
        $userCtx = $result['runtime']['user'];
        $this->assertIsArray($userCtx);
        $this->assertSame('bob', $userCtx['id']);
        $this->assertSame(['learner'], $userCtx['roles']);
    }//end testUserWithUnknownSchemaGetsFallback()

    // -----------------------------------------------------------------------
    // 4. User with profile → full context with calculations
    // -----------------------------------------------------------------------

    /**
     * When the user has a profile object, the service injects its data plus
     * non-materialised calculation results into runtime.user.
     *
     * @return void
     */
    public function testUserWithProfileGetsFullContext(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('charlie');

        $this->userSession->method('getUser')->willReturn($user);

        // Profile object with stored data. getObject() is a real ObjectEntity
        // method; the other getters are @method magic via Entity::__call, so they
        // must be added with addMethods() to be configurable on a mock.
        $profile = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObject'])
            ->addMethods(['getUuid', 'getRegister', 'getSchema', 'getOwner', 'getCreated', 'getUpdated'])
            ->getMock();
        $profile->method('getObject')->willReturn([
            'ncUserId'    => 'charlie',
            'primaryRole' => 'compliance-officer',
        ]);
        $profile->method('getUuid')->willReturn('prof-uuid-1');
        $profile->method('getRegister')->willReturn('my-register');
        $profile->method('getSchema')->willReturn('user-profile');
        $profile->method('getOwner')->willReturn('charlie');
        $profile->method('getCreated')->willReturn(null);
        $profile->method('getUpdated')->willReturn(null);

        // Schema with a non-materialised calculation: displayName = concat(ncUserId, "!")
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn([
            'x-openregister-calculations' => [
                'displayName' => [
                    'materialise' => false,
                    'expression'  => ['concat' => [['prop' => 'ncUserId'], '!']],
                ],
                'storedField' => [
                    'materialise' => true,  // should NOT be overridden here
                    'expression'  => ['prop' => 'primaryRole'],
                ],
            ],
        ]);

        $this->schemaMapper
            ->method('findBySlug')
            ->willReturn([$schema]);

        $this->objectService
            ->method('findAll')
            ->willReturn([$profile]);

        // Evaluator returns values for each expression call.
        $this->evaluator
            ->method('evaluate')
            ->willReturnCallback(static function (array $data, mixed $expr): mixed {
                // Non-materialised displayName calculation.
                if (is_array($expr) && isset($expr['concat'])) {
                    return 'charlie!';
                }

                return null;
            });

        $manifest = ['currentUserSchema' => 'user-profile', 'pages' => []];
        $result   = $this->service->getEnrichedManifest($manifest);

        $this->assertArrayHasKey('runtime', $result);
        $userCtx = $result['runtime']['user'];
        $this->assertIsArray($userCtx);

        // Core identity.
        $this->assertSame('charlie', $userCtx['id']);

        // Raw profile field surfaces in context.
        $this->assertSame('compliance-officer', $userCtx['primaryRole']);

        // Non-materialised calculation is injected.
        $this->assertSame('charlie!', $userCtx['displayName']);

        // Materialised calculation is NOT re-evaluated (so displayName from calc
        // but storedField is already in the raw data via array_merge, not
        // from the evaluator for materialise === true).
        $this->assertArrayNotHasKey('storedField', $userCtx,
            'Materialised calculations must not be re-injected by ManifestService.'
        );
    }//end testUserWithProfileGetsFullContext()

    /**
     * When a calculation expression throws EvaluationException, the service
     * logs a warning and continues, omitting the failed field.
     *
     * @return void
     */
    public function testFailingCalculationIsSkippedGracefully(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('dave');
        $this->userSession->method('getUser')->willReturn($user);

        $profile = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObject'])
            ->addMethods(['getUuid', 'getRegister', 'getSchema', 'getOwner', 'getCreated', 'getUpdated'])
            ->getMock();
        $profile->method('getObject')->willReturn(['ncUserId' => 'dave']);
        $profile->method('getUuid')->willReturn('prof-uuid-2');
        $profile->method('getRegister')->willReturn('reg');
        $profile->method('getSchema')->willReturn('user-profile');
        $profile->method('getOwner')->willReturn('dave');
        $profile->method('getCreated')->willReturn(null);
        $profile->method('getUpdated')->willReturn(null);

        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn([
            'x-openregister-calculations' => [
                'broken' => [
                    'materialise' => false,
                    'expression'  => ['unknownOp' => []],
                ],
            ],
        ]);

        $this->schemaMapper->method('findBySlug')->willReturn([$schema]);
        $this->objectService->method('findAll')->willReturn([$profile]);

        $this->evaluator
            ->method('evaluate')
            ->willThrowException(
                new \OCA\OpenRegister\Service\Calculation\EvaluationException('Unknown operator "unknownOp".')
            );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $manifest = ['currentUserSchema' => 'user-profile', 'pages' => []];
        $result   = $this->service->getEnrichedManifest($manifest);

        $userCtx = $result['runtime']['user'];
        $this->assertSame('dave', $userCtx['id']);
        $this->assertArrayNotHasKey('broken', $userCtx);
    }//end testFailingCalculationIsSkippedGracefully()
}//end class
