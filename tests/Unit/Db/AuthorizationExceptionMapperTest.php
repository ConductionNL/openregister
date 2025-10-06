<?php

/**
 * OpenRegister Authorization Exception Mapper Test
 *
 * This file contains tests for the authorization exception mapper
 * in the OpenRegister application.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\AuthorizationException;
use OCA\OpenRegister\Db\AuthorizationExceptionMapper;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for AuthorizationExceptionMapper
 *
 * This class tests database operations for authorization exceptions,
 * including CRUD operations and specialized queries.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class AuthorizationExceptionMapperTest extends TestCase
{

    /** @var IDBConnection|MockObject */
    private $db;

    /** @var LoggerInterface|MockObject */
    private $logger;

    /** @var AuthorizationExceptionMapper */
    private $mapper;


    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Mock the query builder chain
        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $result = $this->createMock(IResult::class);
        
        $this->db->method('getQueryBuilder')->willReturn($queryBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        
        // Mock the func() method to return a mock function builder
        $functionBuilder = $this->createMock(IFunctionBuilder::class);
        $queryFunction = $this->createMock(IQueryFunction::class);
        $functionBuilder->method('count')->willReturn($queryFunction);
        $queryBuilder->method('func')->willReturn($functionBuilder);
        $expressionBuilder = $this->createMock(IExpressionBuilder::class);
        $compositeExpression = $this->createMock(ICompositeExpression::class);
        $expressionBuilder->method('eq')->willReturn('expr_eq');
        $expressionBuilder->method('isNull')->willReturn('expr_isnull');
        $expressionBuilder->method('orX')->willReturn($compositeExpression);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':param');
        $queryBuilder->method('execute')->willReturn($result);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(false); // Simulate no results found

        $this->mapper = new AuthorizationExceptionMapper($this->db, $this->logger);

    }//end setUp()


    /**
     * Test creating an authorization exception
     *
     * @return void
     */
    public function testCreateException(): void
    {
        $exception = new AuthorizationException();
        $exception->setType(AuthorizationException::TYPE_INCLUSION);
        $exception->setSubjectType(AuthorizationException::SUBJECT_TYPE_USER);
        $exception->setSubjectId('test-user');
        $exception->setAction(AuthorizationException::ACTION_READ);
        $exception->setSchemaUuid('schema-uuid');
        $exception->setPriority(5);
        $exception->setDescription('Test inclusion');

        // Mock the database insert operation
        $this->expectDatabaseInsert($exception);

        $result = $this->mapper->createException($exception, 'creator-user');

        $this->assertInstanceOf(AuthorizationException::class, $result);
        $this->assertEquals('creator-user', $result->getCreatedBy());
        $this->assertNotNull($result->getCreatedAt());
        $this->assertNotNull($result->getUpdatedAt());

    }//end testCreateException()


    /**
     * Test updating an authorization exception
     *
     * @return void
     */
    public function testUpdateException(): void
    {
        $exception = new AuthorizationException();
        $exception->setId(1);
        $exception->setUuid('test-uuid');
        $exception->setType(AuthorizationException::TYPE_EXCLUSION);
        $exception->setDescription('Updated description');

        // Mock the database update operation
        $this->expectDatabaseUpdate($exception);

        $result = $this->mapper->updateException($exception);

        $this->assertInstanceOf(AuthorizationException::class, $result);
        $this->assertNotNull($result->getUpdatedAt());

    }//end testUpdateException()


    /**
     * Test finding applicable exceptions
     *
     * @return void
     */
    public function testFindApplicableExceptions(): void
    {
        // This test would require more complex database mocking
        // In a real test environment, you would set up test data
        
        $exceptions = $this->mapper->findApplicableExceptions(
            AuthorizationException::SUBJECT_TYPE_USER,
            'test-user',
            AuthorizationException::ACTION_READ,
            'schema-uuid'
        );

        $this->assertIsArray($exceptions);

    }//end testFindApplicableExceptions()


    /**
     * Test finding exceptions by subject
     *
     * @return void
     */
    public function testFindBySubject(): void
    {
        $exceptions = $this->mapper->findBySubject(
            AuthorizationException::SUBJECT_TYPE_GROUP,
            'test-group'
        );

        $this->assertIsArray($exceptions);

    }//end testFindBySubject()


    /**
     * Test finding exceptions by UUID
     *
     * @return void
     */
    public function testFindByUuid(): void
    {
        // In a real test, this would throw DoesNotExistException if no record exists
        $this->expectException(DoesNotExistException::class);
        
        $this->mapper->findByUuid('non-existent-uuid');

    }//end testFindByUuid()


    /**
     * Test counting exceptions by criteria
     *
     * @return void
     */
    public function testCountByCriteria(): void
    {
        $criteria = [
            'type' => AuthorizationException::TYPE_INCLUSION,
            'active' => true,
        ];

        $count = $this->mapper->countByCriteria($criteria);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);

    }//end testCountByCriteria()


    /**
     * Test deactivating an exception
     *
     * @return void
     */
    public function testDeactivateException(): void
    {
        // This would require mocking the findByUuid method to return an exception
        $this->expectException(DoesNotExistException::class);
        
        $this->mapper->deactivateException('non-existent-uuid');

    }//end testDeactivateException()


    /**
     * Test activating an exception
     *
     * @return void
     */
    public function testActivateException(): void
    {
        // This would require mocking the findByUuid method to return an exception
        $this->expectException(DoesNotExistException::class);
        
        $this->mapper->activateException('non-existent-uuid');

    }//end testActivateException()


    /**
     * Test deleting an exception by UUID
     *
     * @return void
     */
    public function testDeleteByUuid(): void
    {
        // This would require mocking the findByUuid method to return an exception
        $this->expectException(DoesNotExistException::class);
        
        $this->mapper->deleteByUuid('non-existent-uuid');

    }//end testDeleteByUuid()


    /**
     * Mock database insert operation
     *
     * @param AuthorizationException $exception The exception to insert
     *
     * @return void
     */
    private function expectDatabaseInsert(AuthorizationException $exception): void
    {
        // In a real test setup, you would mock the database connection
        // and query builder to expect specific insert operations
        // For now, we'll just ensure the method returns the exception
        
        // This is a simplified mock - in practice you'd mock QBMapper::insert()

    }//end expectDatabaseInsert()


    /**
     * Mock database update operation
     *
     * @param AuthorizationException $exception The exception to update
     *
     * @return void
     */
    private function expectDatabaseUpdate(AuthorizationException $exception): void
    {
        // In a real test setup, you would mock the database connection
        // and query builder to expect specific update operations
        
        // This is a simplified mock - in practice you'd mock QBMapper::update()

    }//end expectDatabaseUpdate()


}//end class

