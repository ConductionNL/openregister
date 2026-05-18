<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AggregationController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationResult;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AggregationController.
 *
 * @covers \OCA\OpenRegister\Controller\AggregationController
 */
class AggregationControllerTest extends TestCase
{

    private AggregationController $controller;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private AggregationRunner&MockObject $runner;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private Register&MockObject $register;
    private Schema&MockObject $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->runner         = $this->createMock(AggregationRunner::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $request = $this->createMock(IRequest::class);

        $this->controller = new AggregationController(
            appName: 'openregister',
            request: $request,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            runner: $this->runner,
            userSession: $this->userSession,
            logger: $this->logger
        );

        $this->register = $this->createMock(Register::class);
        $this->schema   = $this->createMock(Schema::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testAggregateReturnsMissHeaderOnFreshComputation(): void
    {
        $this->registerMapper->method('find')->willReturn($this->register);
        $this->schemaMapper->method('find')->willReturn($this->schema);

        $this->schema->method('getConfiguration')->willReturn([
            'x-openregister-aggregations' => [
                ['name' => 'totalItems', 'metric' => 'count'],
            ],
        ]);

        $freshResult = new AggregationResult(value: 5, groups: null, backend: 'postgres', cached: false);
        $this->runner->method('run')->willReturn($freshResult);

        $response = $this->controller->aggregate(
            register: 'test-register',
            schema: 'test-schema',
            name: 'totalItems'
        );

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'miss', actual: $response->getHeaders()['X-OR-Cache']);
    }

    public function testAggregateReturnsHitHeaderOnCachedResult(): void
    {
        $this->registerMapper->method('find')->willReturn($this->register);
        $this->schemaMapper->method('find')->willReturn($this->schema);

        $this->schema->method('getConfiguration')->willReturn([
            'x-openregister-aggregations' => [
                ['name' => 'totalItems', 'metric' => 'count'],
            ],
        ]);

        $cachedResult = new AggregationResult(value: 5, groups: null, backend: 'postgres', cached: true);
        $this->runner->method('run')->willReturn($cachedResult);

        $response = $this->controller->aggregate(
            register: 'test-register',
            schema: 'test-schema',
            name: 'totalItems'
        );

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'hit', actual: $response->getHeaders()['X-OR-Cache']);
    }

    public function testAggregateReturns404WhenRegisterNotFound(): void
    {
        $this->registerMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->aggregate(
            register: 'missing',
            schema: 'test-schema',
            name: 'totalItems'
        );

        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
        $headers = $response->getHeaders();
        $this->assertArrayNotHasKey(key: 'X-OR-Cache', array: $headers);
    }

    public function testAggregateReturns404WhenAggregationNameNotFound(): void
    {
        $this->registerMapper->method('find')->willReturn($this->register);
        $this->schemaMapper->method('find')->willReturn($this->schema);

        $this->schema->method('getConfiguration')->willReturn([
            'x-openregister-aggregations' => [
                ['name' => 'other', 'metric' => 'count'],
            ],
        ]);

        $response = $this->controller->aggregate(
            register: 'test-register',
            schema: 'test-schema',
            name: 'totalItems'
        );

        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
        $this->assertArrayNotHasKey(key: 'X-OR-Cache', array: $response->getHeaders());
    }

}//end class
