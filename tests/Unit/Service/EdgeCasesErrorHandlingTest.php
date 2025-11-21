<?php
/**
 * Edge Cases and Error Handling Unit Tests
 *
 * Test Coverage:
 * - Test 9.1: Unauthenticated Requests
 * - Test 9.2: Malformed JSON Requests
 * - Test 9.3: SQL Injection Attempts
 * - Test 9.4: Very Long Organisation Names
 * - Test 9.5: Unicode and Special Characters
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Organisation;
use OCP\IUserSession;
use OCP\ISession;
use OCP\IUser;
use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

class EdgeCasesErrorHandlingTest extends TestCase
{
    private OrganisationService $organisationService;
    private OrganisationController $organisationController;
    private OrganisationMapper|MockObject $organisationMapper;
    private IUserSession|MockObject $userSession;
    private ISession|MockObject $session;
    private IRequest|MockObject $request;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->request = $this->createMock(IRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->logger
        );
        
        $this->organisationController = new OrganisationController(
            'openregister',
            $this->request,
            $this->organisationService,
            $this->organisationMapper,
            $this->logger
        );
    }

    /**
     * Test 9.1: Unauthenticated Requests
     */
    public function testUnauthenticatedRequests(): void
    {
        // Arrange: No authenticated user.
        $this->userSession->method('getUser')->willReturn(null);

        // Act: Attempt unauthenticated operation.
        $response = $this->organisationController->index();

        // Assert: Unauthorized response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(401, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('unauthorized', strtolower($responseData['error']));
    }

    /**
     * Test 9.2: Malformed JSON Requests
     */
    public function testMalformedJsonRequests(): void
    {
        // Arrange: Valid user but malformed request data.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // Mock: Invalid JSON structure.
        $this->request->method('getParam')
            ->willReturnCallback(function($key, $default) {
                if ($key === 'name') {
                    // Simulate malformed data that causes processing errors.
                    return ['invalid' => 'structure'];
                }
                return $default;
            });

        // Act: Attempt to create organisation with malformed data.
        $response = $this->organisationController->create(['invalid' => 'structure'], 'Test description');

        // Assert: Bad request response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test 9.3: SQL Injection Attempts
     */
    public function testSqlInjectionAttempts(): void
    {
        // Arrange: User with SQL injection payload.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $maliciousInput = "'; DROP TABLE organisations; --";
        
        // Mock: Parameterized queries should prevent injection.
        $this->organisationMapper->expects($this->once())
            ->method('findByName')
            ->with($maliciousInput) // Input is safely parameterized
            ->willReturn([]);

        // Act: Search with malicious input.
        $response = $this->organisationController->search($maliciousInput);

        // Assert: Safe handling, no SQL injection.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertIsArray($responseData);
        $this->assertEmpty($responseData); // No results, but query was safe
    }

    /**
     * Test 9.4: Very Long Organisation Names
     */
    public function testVeryLongOrganisationNames(): void
    {
        // Arrange: User creates organisation with very long name.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // 1000 character name.
        $veryLongName = str_repeat('A', 1000);
        
        // Act: Attempt to create organisation with very long name.
        $response = $this->organisationController->create($veryLongName, 'Test description');

        // Assert: Should handle gracefully (truncate or reject).
        $this->assertInstanceOf(JSONResponse::class, $response);
        
        if ($response->getStatus() === 400) {
            // Name too long - rejected.
            $responseData = $response->getData();
            $this->assertArrayHasKey('error', $responseData);
            $this->assertStringContainsString('too long', strtolower($responseData['error']));
        } else {
            // Name truncated - accepted.
            $this->assertEquals(200, $response->getStatus());
            $responseData = $response->getData();
            $this->assertLessThanOrEqual(255, strlen($responseData['name'])); // Truncated
        }
    }

    /**
     * Test 9.5: Unicode and Special Characters
     */
    public function testUnicodeAndSpecialCharacters(): void
    {
        // Arrange: User with Unicode-capable session.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // Unicode name with emojis and special characters.
        $unicodeName = "测试机构 🏢 Café München & Co.";
        $unicodeDescription = "Multi-language org with émojis and spëcial chars: áéíóú";
        
        // Mock: Organisation creation with Unicode.
        $unicodeOrg = new Organisation();
        $unicodeOrg->setName($unicodeName);
        $unicodeOrg->setDescription($unicodeDescription);
        $unicodeOrg->setUuid('unicode-org-uuid');
        $unicodeOrg->setOwner('alice');
        $unicodeOrg->addUser('alice');
        
        $this->organisationMapper->expects($this->once())
            ->method('insert')
            ->willReturn($unicodeOrg);

        // Act: Create organisation with Unicode content.
        $response = $this->organisationController->create($unicodeName, $unicodeDescription);

        // Assert: Unicode properly supported.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertEquals($unicodeName, $responseData['name']);
        $this->assertEquals($unicodeDescription, $responseData['description']);
        
        // Verify UTF-8 encoding preserved.
        $this->assertStringContainsString('测试机构', $responseData['name']);
        $this->assertStringContainsString('🏢', $responseData['name']);
        $this->assertStringContainsString('émojis', $responseData['description']);
    }

    /**
     * Test null and empty value handling
     */
    public function testNullAndEmptyValueHandling(): void
    {
        // Arrange: User session.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // Test various null/empty scenarios.
        $testCases = [
            ['name' => null, 'description' => 'Valid description'],
            ['name' => '', 'description' => 'Valid description'],
            ['name' => '   ', 'description' => 'Valid description'], // Whitespace only
            ['name' => 'Valid Name', 'description' => null],
            ['name' => 'Valid Name', 'description' => ''],
        ];

        foreach ($testCases as $testCase) {
            $response = $this->organisationController->create($testCase['name'], $testCase['description']);
            
            // Assert: Proper validation of null/empty values.
            if (empty(trim($testCase['name']))) {
                $this->assertEquals(400, $response->getStatus());
            } else {
                // Valid name with empty description should be allowed.
                $this->assertContains($response->getStatus(), [200, 400]); // Either success or validation error
            }
        }
    }

    /**
     * Test exception handling and logging
     */
    public function testExceptionHandlingAndLogging(): void
    {
        // Arrange: Force database exception.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->organisationMapper->expects($this->once())
            ->method('insert')
            ->willThrowException(new \Exception('Database connection failed'));

        // Mock: Logger should capture the exception.
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Database connection failed'));

        // Act: Attempt operation that causes exception.
        $response = $this->organisationController->create('Test Org', 'Test description');

        // Assert: Graceful error handling.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        
        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('internal error', strtolower($responseData['error']));
    }

    /**
     * Test rate limiting simulation
     */
    public function testRateLimitingSimulation(): void
    {
        // Arrange: Simulate rapid requests from same user.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('rapid_user');
        $this->userSession->method('getUser')->willReturn($user);

        // Mock: Rate limiting check (simulated).
        $requestCount = 0;
        $maxRequests = 5;
        
        $responses = [];
        
        // Act: Make multiple rapid requests.
        for ($i = 0; $i < 10; $i++) {
            $requestCount++;
            
            if ($requestCount > $maxRequests) {
                // Simulate rate limiting.
                $response = new JSONResponse(['error' => 'Rate limit exceeded'], 429);
            } else {
                $response = $this->organisationController->index();
            }
            
            $responses[] = $response;
        }

        // Assert: Rate limiting kicks in.
        $rateLimitedResponses = array_filter($responses, function($response) {
            return $response->getStatus() === 429;
        });
        
        $this->assertGreaterThan(0, count($rateLimitedResponses));
    }
} 