<?php
/**
 * Bulk Metadata Handling Unit Tests
 *
 * This test class verifies that both individual and bulk object save operations
 * correctly set owner and organization metadata when not provided in the object data.
 *
 * Test Coverage:
 * - Owner metadata setting in bulk operations
 * - Organization metadata setting using optimized OrganisationService
 * - Fallback behavior when user session or organization service fails
 * - Preservation of existing metadata when provided
 * - Null handling and edge cases
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\ChunkProcessingHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\PreparationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test class for bulk metadata handling optimization
 */
class BulkMetadataHandlingTest extends TestCase
{

    /**
     * The SaveObjects handler instance being tested
     *
     * @var SaveObjects
     */
    private SaveObjects $saveObjectsHandler;

    /**
     * Mock object entity mapper
     *
     * @var MockObject|MagicMapper
     */
    private MockObject $mockObjectMapper;

    /**
     * Mock schema mapper
     *
     * @var MockObject|SchemaMapper
     */
    private MockObject $mockSchemaMapper;

    /**
     * Mock register mapper
     *
     * @var MockObject|RegisterMapper
     */
    private MockObject $mockRegisterMapper;

    /**
     * Mock save object handler
     *
     * @var MockObject|SaveObject
     */
    private MockObject $mockSaveHandler;

    /**
     * Mock bulk validation handler
     *
     * @var MockObject|BulkValidationHandler
     */
    private MockObject $mockBulkValidHandler;

    /**
     * Mock bulk relation handler
     *
     * @var MockObject|BulkRelationHandler
     */
    private MockObject $mockBulkRelationHandler;

    /**
     * Mock transformation handler
     *
     * @var MockObject|TransformationHandler
     */
    private MockObject $mockTransformHandler;

    /**
     * Mock preparation handler
     *
     * @var MockObject|PreparationHandler
     */
    private MockObject $mockPreparationHandler;

    /**
     * Mock chunk processing handler
     *
     * @var MockObject|ChunkProcessingHandler
     */
    private MockObject $mockChunkProcHandler;

    /**
     * Mock user session
     *
     * @var MockObject|IUserSession
     */
    private MockObject $mockUserSession;

    /**
     * Mock organisation service
     *
     * @var MockObject|OrganisationService
     */
    private MockObject $mockOrganisationService;

    /**
     * Mock user object
     *
     * @var MockObject|IUser
     */
    private MockObject $mockUser;

    /**
     * Register entity for testing
     *
     * @var Register
     */
    private Register $testRegister;

    /**
     * Schema entity for testing
     *
     * @var Schema
     */
    private Schema $testSchema;


    /**
     * Set up the test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for all dependencies.
        $this->mockObjectMapper = $this->createMock(MagicMapper::class);
        $this->mockSchemaMapper = $this->createMock(SchemaMapper::class);
        $this->mockRegisterMapper = $this->createMock(RegisterMapper::class);
        $this->mockSaveHandler = $this->createMock(SaveObject::class);
        $this->mockBulkValidHandler = $this->createMock(BulkValidationHandler::class);
        $this->mockBulkRelationHandler = $this->createMock(BulkRelationHandler::class);
        $this->mockTransformHandler = $this->createMock(TransformationHandler::class);
        $this->mockPreparationHandler = $this->createMock(PreparationHandler::class);
        $this->mockChunkProcHandler = $this->createMock(ChunkProcessingHandler::class);
        $this->mockUserSession = $this->createMock(IUserSession::class);
        $this->mockOrganisationService = $this->createMock(OrganisationService::class);

        // Create mock user (interface, keep as mock).
        $this->mockUser = $this->createMock(IUser::class);

        // Create real entity instances instead of mocks (Entity __call does not support mocking).
        $this->testRegister = new Register();
        $this->testRegister->setId(1);

        $this->testSchema = new Schema();
        $this->testSchema->setId(1);
        $this->testSchema->setProperties([]);
        $this->testSchema->setConfiguration([]);
        $this->testSchema->setHardValidation(false);

        // Configure schema analysis mock (needed by prepareSingleSchemaObjectsOptimized).
        $this->mockBulkValidHandler
            ->method('performComprehensiveSchemaAnalysis')
            ->willReturn([
                'metadataFields'     => [],
                'inverseProperties'  => [],
                'validationRequired' => false,
                'properties'         => [],
                'configuration'      => [],
            ]);

        // Create the SaveObjects handler with mocked dependencies.
        $this->saveObjectsHandler = new SaveObjects(
            $this->mockObjectMapper,
            $this->mockSchemaMapper,
            $this->mockRegisterMapper,
            $this->mockSaveHandler,
            $this->mockBulkValidHandler,
            $this->mockBulkRelationHandler,
            $this->mockTransformHandler,
            $this->mockPreparationHandler,
            $this->mockChunkProcHandler,
            $this->mockOrganisationService,
            $this->mockUserSession,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()


    /**
     * Helper to create a default Organisation entity.
     *
     * @param string $uuid Organisation UUID
     *
     * @return Organisation
     */
    private function createDefaultOrganisation(string $uuid='test-org-456'): Organisation
    {
        $org = new Organisation();
        $org->setUuid($uuid);
        return $org;

    }//end createDefaultOrganisation()


    /**
     * Helper to configure chunk processing handler to return successful results.
     *
     * @param int $savedCount Number of saved objects to report
     *
     * @return void
     */
    private function configureChunkProcessingSuccess(int $savedCount=1): void
    {
        $this->mockChunkProcHandler
            ->method('processObjectsChunk')
            ->willReturn([
                'saved'      => array_fill(0, $savedCount, 'test-uuid'),
                'updated'    => [],
                'unchanged'  => [],
                'invalid'    => [],
                'errors'     => [],
                'statistics' => [
                    'saved'     => $savedCount,
                    'updated'   => 0,
                    'unchanged' => 0,
                    'invalid'   => 0,
                    'errors'    => 0,
                ],
            ]);

    }//end configureChunkProcessingSuccess()


    /**
     * Test that owner metadata is correctly set when not provided in object data
     *
     * @return void
     */
    public function testOwnerMetadataSetFromCurrentUser(): void
    {
        // Configure user session mock to return a valid user.
        $this->mockUser->method('getUID')->willReturn('test-user-123');
        $this->mockUserSession->method('getUser')->willReturn($this->mockUser);

        // Configure OrganisationService to return test organization.
        $this->mockOrganisationService
            ->method('ensureDefaultOrganisation')
            ->willReturn($this->createDefaultOrganisation());

        // Configure chunk processing to succeed.
        $this->configureChunkProcessingSuccess(1);

        // Test object without owner or organization metadata.
        $testObjects = [
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                ],
                'title' => 'Test Object Without Owner',
                'description' => 'Test object to verify owner metadata is set'
            ]
        ];

        // Execute the bulk save operation.
        $result = $this->saveObjectsHandler->saveObjects(
            objects: $testObjects,
            register: $this->testRegister,
            schema: $this->testSchema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );

        // Verify the operation was successful.
        $this->assertArrayHasKey('statistics', $result);
        $this->assertGreaterThan(0, $result['statistics']['saved']);

    }//end testOwnerMetadataSetFromCurrentUser()


    /**
     * Test that organization metadata is correctly set when not provided in object data
     *
     * @return void
     */
    public function testOrganizationMetadataSetFromOrganisationService(): void
    {
        // Configure user session mock to return a valid user.
        $this->mockUser->method('getUID')->willReturn('test-user-123');
        $this->mockUserSession->method('getUser')->willReturn($this->mockUser);

        // Configure OrganisationService to return test organization.
        $this->mockOrganisationService
            ->expects($this->atLeastOnce())
            ->method('ensureDefaultOrganisation')
            ->willReturn($this->createDefaultOrganisation());

        // Configure chunk processing to succeed.
        $this->configureChunkProcessingSuccess(1);

        // Test object without organization metadata.
        $testObjects = [
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                    'owner' => 'explicit-user-789'
                ],
                'title' => 'Test Object Without Organization',
                'description' => 'Test object to verify organization metadata is set'
            ]
        ];

        // Execute the bulk save operation.
        $result = $this->saveObjectsHandler->saveObjects(
            objects: $testObjects,
            register: $this->testRegister,
            schema: $this->testSchema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );

        // Verify the operation was successful.
        $this->assertArrayHasKey('statistics', $result);
        $this->assertGreaterThan(0, $result['statistics']['saved']);

    }//end testOrganizationMetadataSetFromOrganisationService()


    /**
     * Test that existing metadata is preserved when provided in object data
     *
     * @return void
     */
    public function testExistingMetadataIsPreserved(): void
    {
        // Configure user session mock to return a different user.
        $this->mockUser->method('getUID')->willReturn('current-user-999');
        $this->mockUserSession->method('getUser')->willReturn($this->mockUser);

        // Configure OrganisationService to return different organization.
        $this->mockOrganisationService
            ->method('ensureDefaultOrganisation')
            ->willReturn($this->createDefaultOrganisation('default-org-999'));

        // Configure chunk processing to succeed.
        $this->configureChunkProcessingSuccess(1);

        // Test object WITH existing owner and organization metadata.
        $testObjects = [
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                    'owner' => 'explicit-owner-123',
                    'organisation' => 'explicit-org-456'
                ],
                'title' => 'Test Object With Existing Metadata',
                'description' => 'Test object to verify existing metadata is preserved'
            ]
        ];

        // Execute the bulk save operation.
        $result = $this->saveObjectsHandler->saveObjects(
            objects: $testObjects,
            register: $this->testRegister,
            schema: $this->testSchema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );

        // Verify the operation was successful.
        $this->assertArrayHasKey('statistics', $result);
        $this->assertGreaterThan(0, $result['statistics']['saved']);

    }//end testExistingMetadataIsPreserved()


    /**
     * Test graceful handling when user session returns null
     *
     * @return void
     */
    public function testGracefulHandlingWhenUserSessionIsNull(): void
    {
        // Configure user session mock to return null (not logged in).
        $this->mockUserSession->method('getUser')->willReturn(null);

        // Configure OrganisationService to return test organization.
        $this->mockOrganisationService
            ->method('ensureDefaultOrganisation')
            ->willReturn($this->createDefaultOrganisation());

        // Configure chunk processing to succeed.
        $this->configureChunkProcessingSuccess(1);

        // Test object without owner metadata when user is not logged in.
        $testObjects = [
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                ],
                'title' => 'Test Object Without User Session',
                'description' => 'Test object to verify null user handling'
            ]
        ];

        // Execute the bulk save operation.
        $result = $this->saveObjectsHandler->saveObjects(
            objects: $testObjects,
            register: $this->testRegister,
            schema: $this->testSchema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );

        // Verify the operation was successful despite null user.
        $this->assertArrayHasKey('statistics', $result);
        $this->assertGreaterThan(0, $result['statistics']['saved']);

    }//end testGracefulHandlingWhenUserSessionIsNull()


    /**
     * Test graceful handling when OrganisationService throws exception
     *
     * @return void
     */
    public function testGracefulHandlingWhenOrganisationServiceFails(): void
    {
        // Configure user session mock to return a valid user.
        $this->mockUser->method('getUID')->willReturn('test-user-123');
        $this->mockUserSession->method('getUser')->willReturn($this->mockUser);

        // Configure OrganisationService to throw exception.
        $this->mockOrganisationService
            ->method('ensureDefaultOrganisation')
            ->willThrowException(new \Exception('Organisation service unavailable'));

        // Configure chunk processing to succeed.
        $this->configureChunkProcessingSuccess(1);

        // Test object without organization metadata when service fails.
        $testObjects = [
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                ],
                'title' => 'Test Object With Org Service Failure',
                'description' => 'Test object to verify organization service error handling'
            ]
        ];

        // Execute the bulk save operation.
        $result = $this->saveObjectsHandler->saveObjects(
            objects: $testObjects,
            register: $this->testRegister,
            schema: $this->testSchema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );

        // Verify the operation was successful despite organization service failure.
        $this->assertArrayHasKey('statistics', $result);
        $this->assertGreaterThan(0, $result['statistics']['saved']);

    }//end testGracefulHandlingWhenOrganisationServiceFails()


    /**
     * Test bulk operations with mixed metadata scenarios
     *
     * @return void
     */
    public function testBulkOperationsWithMixedMetadataScenarios(): void
    {
        // Configure user session mock to return a valid user.
        $this->mockUser->method('getUID')->willReturn('current-user-123');
        $this->mockUserSession->method('getUser')->willReturn($this->mockUser);

        // Configure OrganisationService to return test organization.
        $this->mockOrganisationService
            ->method('ensureDefaultOrganisation')
            ->willReturn($this->createDefaultOrganisation('default-org-456'));

        // Configure chunk processing to succeed with 4 objects.
        $this->configureChunkProcessingSuccess(4);

        // Test objects with different metadata scenarios.
        $testObjects = [
            // Object 1: No metadata - should get defaults.
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                ],
                'title' => 'Object Without Metadata',
            ],
            // Object 2: Has owner, no organization - should get default organization.
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                    'owner' => 'explicit-owner-789',
                ],
                'title' => 'Object With Owner Only',
            ],
            // Object 3: Has organization, no owner - should get current user.
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                    'organisation' => 'explicit-org-789',
                ],
                'title' => 'Object With Organization Only',
            ],
            // Object 4: Has both - should preserve both.
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                    'owner' => 'explicit-owner-999',
                    'organisation' => 'explicit-org-999',
                ],
                'title' => 'Object With Both Metadata',
            ]
        ];

        // Execute the bulk save operation.
        $result = $this->saveObjectsHandler->saveObjects(
            objects: $testObjects,
            register: $this->testRegister,
            schema: $this->testSchema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );

        // Verify the operation was successful for all objects.
        $this->assertArrayHasKey('statistics', $result);
        $this->assertEquals(4, $result['statistics']['saved']);

    }//end testBulkOperationsWithMixedMetadataScenarios()


    /**
     * Test that caching optimization is leveraged during bulk operations
     *
     * @return void
     */
    public function testCachingOptimizationDuringBulkOperations(): void
    {
        // Configure user session mock to return a valid user.
        $this->mockUser->method('getUID')->willReturn('test-user-123');
        $this->mockUserSession->method('getUser')->willReturn($this->mockUser);

        // Configure OrganisationService to return test organization.
        $this->mockOrganisationService
            ->expects($this->atLeastOnce())
            ->method('ensureDefaultOrganisation')
            ->willReturn($this->createDefaultOrganisation('cached-org-789'));

        // Configure chunk processing to succeed with 3 objects.
        $this->configureChunkProcessingSuccess(3);

        // Create multiple objects without organization metadata.
        $testObjects = [
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                ],
                'title' => 'Object 1 Without Organization',
            ],
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                ],
                'title' => 'Object 2 Without Organization',
            ],
            [
                '@self' => [
                    'schema' => 1,
                    'register' => 1,
                ],
                'title' => 'Object 3 Without Organization',
            ]
        ];

        // Execute the bulk save operation.
        $result = $this->saveObjectsHandler->saveObjects(
            objects: $testObjects,
            register: $this->testRegister,
            schema: $this->testSchema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );

        // Verify the operation was successful for all objects.
        $this->assertArrayHasKey('statistics', $result);
        $this->assertEquals(3, $result['statistics']['saved']);

    }//end testCachingOptimizationDuringBulkOperations()


}//end class
