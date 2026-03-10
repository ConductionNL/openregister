<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Service\ApplicationService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class ApplicationServiceTest extends TestCase
{

    /**
     * @var ApplicationMapper&MockObject
     */
    private ApplicationMapper $applicationMapper;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    private ApplicationService $service;

    protected function setUp(): void
    {
        $this->applicationMapper = $this->createMock(ApplicationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ApplicationService(
            $this->applicationMapper,
            $this->logger
        );
    }

    // --- findAll ---

    public function testFindAllReturnsArrayOfApplications(): void
    {
        $app1 = new Application();
        $app2 = new Application();

        $this->applicationMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$app1, $app2]);

        $result = $this->service->findAll();

        $this->assertCount(2, $result);
    }

    public function testFindAllWithLimitAndOffset(): void
    {
        $this->applicationMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->findAll(10, 5, ['key' => 'value']);

        $this->assertSame([], $result);
    }

    public function testFindAllWithNoResults(): void
    {
        $this->applicationMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->findAll();

        $this->assertSame([], $result);
    }

    // --- find ---

    public function testFindReturnsApplication(): void
    {
        $app = new Application();
        $reflection = new \ReflectionClass($app);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($app, 1);

        $this->applicationMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($app);

        $result = $this->service->find(1);

        $this->assertSame($app, $result);
    }

    public function testFindThrowsDoesNotExistException(): void
    {
        $this->applicationMapper
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(DoesNotExistException::class);

        $this->service->find(999);
    }

    // --- create ---

    public function testCreateReturnsCreatedApplication(): void
    {
        $data = ['name' => 'Test App'];
        $app = new Application();
        $reflection = new \ReflectionClass($app);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($app, 1);

        $this->applicationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->willReturn($app);

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $result = $this->service->create($data);

        $this->assertSame($app, $result);
        $this->assertSame(1, $result->getId());
    }

    public function testCreateWithEmptyData(): void
    {
        $app = new Application();

        $this->applicationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->willReturn($app);

        $result = $this->service->create([]);

        $this->assertSame($app, $result);
    }

    // --- update ---

    public function testUpdateReturnsUpdatedApplication(): void
    {
        $data = ['name' => 'Updated App'];
        $app = new Application();

        $this->applicationMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->willReturn($app);

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $result = $this->service->update(1, $data);

        $this->assertSame($app, $result);
    }

    public function testUpdateThrowsDoesNotExistException(): void
    {
        $this->applicationMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(DoesNotExistException::class);

        $this->service->update(999, ['name' => 'fail']);
    }

    // --- delete ---

    public function testDeleteRemovesApplication(): void
    {
        $app = new Application();

        $this->applicationMapper
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($app);

        $this->applicationMapper
            ->expects($this->once())
            ->method('delete')
            ->with($app);

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->service->delete(1);
    }

    public function testDeleteThrowsDoesNotExistException(): void
    {
        $this->applicationMapper
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(DoesNotExistException::class);

        $this->service->delete(999);
    }

    // --- countAll ---

    public function testCountAllReturnsCount(): void
    {
        $this->applicationMapper
            ->expects($this->once())
            ->method('countAll')
            ->willReturn(42);

        $result = $this->service->countAll();

        $this->assertSame(42, $result);
    }

    public function testCountAllReturnsZeroWhenEmpty(): void
    {
        $this->applicationMapper
            ->expects($this->once())
            ->method('countAll')
            ->willReturn(0);

        $result = $this->service->countAll();

        $this->assertSame(0, $result);
    }
}
