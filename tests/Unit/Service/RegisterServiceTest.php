<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Test class for RegisterService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class RegisterServiceTest extends TestCase
{
    private RegisterService $registerService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private IUserSession $userSession;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create RegisterService instance
        $this->registerService = new RegisterService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->userSession,
            $this->logger
        );
    }

    /**
     * Test createRegister method with valid data
     */
    public function testCreateRegisterWithValidData(): void
    {
        $registerData = [
            'title' => 'Test Register',
            'description' => 'Test Description',
            'version' => '1.0.0'
        ];

        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('setTitle')->with('Test Register');
        $register->method('setDescription')->with('Test Description');
        $register->method('setVersion')->with('1.0.0');
        $register->method('setUserId')->with($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('insert')
            ->willReturn($register);

        $result = $this->registerService->createRegister($registerData);

        $this->assertEquals($register, $result);
    }

    /**
     * Test createRegister method with no user session
     */
    public function testCreateRegisterWithNoUserSession(): void
    {
        $registerData = [
            'title' => 'Test Register',
            'description' => 'Test Description'
        ];

        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User session required');

        $this->registerService->createRegister($registerData);
    }

    /**
     * Test createRegister method with missing required fields
     */
    public function testCreateRegisterWithMissingRequiredFields(): void
    {
        $registerData = [
            'description' => 'Test Description'
            // Missing 'title' which is required
        ];

        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Title is required');

        $this->registerService->createRegister($registerData);
    }

    /**
     * Test updateRegister method with valid data
     */
    public function testUpdateRegisterWithValidData(): void
    {
        $registerId = 'test-register-id';
        $registerData = [
            'title' => 'Updated Register',
            'description' => 'Updated Description',
            'version' => '2.0.0'
        ];

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($registerId);
        $register->method('setTitle')->with('Updated Register');
        $register->method('setDescription')->with('Updated Description');
        $register->method('setVersion')->with('2.0.0');

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->with($register)
            ->willReturn($register);

        $result = $this->registerService->updateRegister($registerId, $registerData);

        $this->assertEquals($register, $result);
    }

    /**
     * Test updateRegister method with non-existent register
     */
    public function testUpdateRegisterWithNonExistentRegister(): void
    {
        $registerId = 'non-existent-id';
        $registerData = [
            'title' => 'Updated Register'
        ];

        // Mock register mapper to throw exception
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Register not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Register not found');

        $this->registerService->updateRegister($registerId, $registerData);
    }

    /**
     * Test deleteRegister method with existing register
     */
    public function testDeleteRegisterWithExistingRegister(): void
    {
        $registerId = 'test-register-id';

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($registerId);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('delete')
            ->with($register)
            ->willReturn($register);

        $result = $this->registerService->deleteRegister($registerId);

        $this->assertEquals($register, $result);
    }

    /**
     * Test deleteRegister method with non-existent register
     */
    public function testDeleteRegisterWithNonExistentRegister(): void
    {
        $registerId = 'non-existent-id';

        // Mock register mapper to throw exception
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Register not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Register not found');

        $this->registerService->deleteRegister($registerId);
    }

    /**
     * Test getRegister method with existing register
     */
    public function testGetRegisterWithExistingRegister(): void
    {
        $registerId = 'test-register-id';

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($registerId);
        $register->method('getTitle')->willReturn('Test Register');

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willReturn($register);

        $result = $this->registerService->getRegister($registerId);

        $this->assertEquals($register, $result);
    }

    /**
     * Test getRegister method with non-existent register
     */
    public function testGetRegisterWithNonExistentRegister(): void
    {
        $registerId = 'non-existent-id';

        // Mock register mapper to throw exception
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($registerId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Register not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Register not found');

        $this->registerService->getRegister($registerId);
    }

    /**
     * Test getAllRegisters method
     */
    public function testGetAllRegisters(): void
    {
        $limit = 10;
        $offset = 0;

        // Create mock registers
        $register1 = $this->createMock(Register::class);
        $register1->method('getId')->willReturn('1');
        $register1->method('getTitle')->willReturn('Register 1');

        $register2 = $this->createMock(Register::class);
        $register2->method('getId')->willReturn('2');
        $register2->method('getTitle')->willReturn('Register 2');

        $registers = [$register1, $register2];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->with($limit, $offset)
            ->willReturn($registers);

        $result = $this->registerService->getAllRegisters($limit, $offset);

        $this->assertEquals($registers, $result);
    }

    /**
     * Test getAllRegisters method with default parameters
     */
    public function testGetAllRegistersWithDefaultParameters(): void
    {
        // Create mock registers
        $registers = [$this->createMock(Register::class)];

        // Mock register mapper with default parameters
        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->with(20, 0) // default limit and offset
            ->willReturn($registers);

        $result = $this->registerService->getAllRegisters();

        $this->assertEquals($registers, $result);
    }

    /**
     * Test getRegistersByUser method
     */
    public function testGetRegistersByUser(): void
    {
        $userId = 'testuser';
        $limit = 10;
        $offset = 0;

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock registers
        $register1 = $this->createMock(Register::class);
        $register1->method('getId')->willReturn('1');

        $register2 = $this->createMock(Register::class);
        $register2->method('getId')->willReturn('2');

        $registers = [$register1, $register2];

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('findAllByUser')
            ->with($userId, $limit, $offset)
            ->willReturn($registers);

        $result = $this->registerService->getRegistersByUser($limit, $offset);

        $this->assertEquals($registers, $result);
    }

    /**
     * Test getRegistersByUser method with no user session
     */
    public function testGetRegistersByUserWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->registerService->getRegistersByUser();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getRegisterStatistics method
     */
    public function testGetRegisterStatistics(): void
    {
        $registerId = 'test-register-id';

        // Create mock statistics
        $statistics = [
            'total_schemas' => 5,
            'total_objects' => 100,
            'total_size' => 1024000,
            'last_updated' => '2024-01-01T00:00:00Z'
        ];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('getStatistics')
            ->with($registerId)
            ->willReturn($statistics);

        $result = $this->registerService->getRegisterStatistics($registerId);

        $this->assertEquals($statistics, $result);
    }

    /**
     * Test getRegisterStatistics method with non-existent register
     */
    public function testGetRegisterStatisticsWithNonExistentRegister(): void
    {
        $registerId = 'non-existent-id';

        // Mock register mapper to throw exception
        $this->registerMapper->expects($this->once())
            ->method('getStatistics')
            ->with($registerId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Register not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Register not found');

        $this->registerService->getRegisterStatistics($registerId);
    }

    /**
     * Test searchRegisters method
     */
    public function testSearchRegisters(): void
    {
        $query = 'test register';
        $limit = 10;
        $offset = 0;

        // Create mock registers
        $register1 = $this->createMock(Register::class);
        $register1->method('getId')->willReturn('1');
        $register1->method('getTitle')->willReturn('Test Register 1');

        $register2 = $this->createMock(Register::class);
        $register2->method('getId')->willReturn('2');
        $register2->method('getTitle')->willReturn('Test Register 2');

        $registers = [$register1, $register2];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('search')
            ->with($query, $limit, $offset)
            ->willReturn($registers);

        $result = $this->registerService->searchRegisters($query, $limit, $offset);

        $this->assertEquals($registers, $result);
    }

    /**
     * Test searchRegisters method with empty query
     */
    public function testSearchRegistersWithEmptyQuery(): void
    {
        $query = '';
        $limit = 10;
        $offset = 0;

        $result = $this->registerService->searchRegisters($query, $limit, $offset);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test validateRegisterData method with valid data
     */
    public function testValidateRegisterDataWithValidData(): void
    {
        $registerData = [
            'title' => 'Test Register',
            'description' => 'Test Description',
            'version' => '1.0.0'
        ];

        $result = $this->registerService->validateRegisterData($registerData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue($result['valid']);
        $this->assertCount(0, $result['errors']);
    }

    /**
     * Test validateRegisterData method with invalid data
     */
    public function testValidateRegisterDataWithInvalidData(): void
    {
        $registerData = [
            'description' => 'Test Description',
            'version' => 'invalid-version'
            // Missing 'title' which is required
        ];

        $result = $this->registerService->validateRegisterData($registerData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
    }

    /**
     * Test validateRegisterData method with empty data
     */
    public function testValidateRegisterDataWithEmptyData(): void
    {
        $registerData = [];

        $result = $this->registerService->validateRegisterData($registerData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
    }
}
