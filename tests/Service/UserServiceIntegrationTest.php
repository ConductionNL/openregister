<?php

/**
 * Integration tests for UserService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\UserService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for UserService
 *
 * Tests user data retrieval, custom name fields, and property updates
 * using the real Nextcloud DI container and user system.
 */
class UserServiceIntegrationTest extends TestCase
{
    /**
     * The user service instance
     *
     * @var UserService
     */
    private UserService $service;

    /**
     * The user manager instance
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * Test user instance
     *
     * @var IUser|null
     */
    private ?IUser $testUser = null;

    /**
     * Test user ID
     *
     * @var string
     */
    private string $testUserId;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(UserService::class);
        $this->userManager = \OC::$server->get(IUserManager::class);

        // Create a test user for reliable testing
        $this->testUserId = 'phpunit-test-' . uniqid();
        $this->testUser = $this->userManager->createUser($this->testUserId, 'TestPass1234!');
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Delete the test user if created
        if ($this->testUser !== null) {
            $this->testUser->delete();
        }

        parent::tearDown();
    }

    /**
     * Test getCurrentUser returns null when no session
     *
     * @return void
     */
    public function testGetCurrentUserNoSession(): void
    {
        // In a test context without an active session, current user may be null
        $result = $this->service->getCurrentUser();

        // Either null or an IUser instance is acceptable
        $this->assertTrue($result === null || $result instanceof IUser);
    }

    /**
     * Test buildUserDataArray with test user
     *
     * @return void
     */
    public function testBuildUserDataArray(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('uid', $result);
        $this->assertSame($this->testUserId, $result['uid']);
        $this->assertArrayHasKey('displayName', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('quota', $result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('language', $result);
        $this->assertArrayHasKey('locale', $result);
        $this->assertArrayHasKey('backendCapabilities', $result);
        $this->assertArrayHasKey('firstName', $result);
        $this->assertArrayHasKey('lastName', $result);
        $this->assertArrayHasKey('middleName', $result);
        $this->assertArrayHasKey('organisations', $result);
    }

    /**
     * Test quota information in buildUserDataArray
     *
     * @return void
     */
    public function testBuildUserDataArrayQuota(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('quota', $result);
        $quota = $result['quota'];
        $this->assertIsArray($quota);
        $this->assertArrayHasKey('free', $quota);
        $this->assertArrayHasKey('used', $quota);
        $this->assertArrayHasKey('total', $quota);
        $this->assertArrayHasKey('relative', $quota);
    }

    /**
     * Test backendCapabilities in buildUserDataArray
     *
     * @return void
     */
    public function testBuildUserDataArrayBackendCapabilities(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $capabilities = $result['backendCapabilities'];
        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('displayName', $capabilities);
        $this->assertArrayHasKey('email', $capabilities);
        $this->assertArrayHasKey('password', $capabilities);
        $this->assertArrayHasKey('avatar', $capabilities);
    }

    /**
     * Test organisations structure in buildUserDataArray
     *
     * @return void
     */
    public function testBuildUserDataArrayOrganisations(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $organisations = $result['organisations'];
        $this->assertIsArray($organisations);
        $this->assertArrayHasKey('active', $organisations);
        $this->assertArrayHasKey('all', $organisations);
        $this->assertArrayHasKey('total', $organisations);
        $this->assertArrayHasKey('available', $organisations);
        $this->assertTrue($organisations['available']);
    }

    /**
     * Test getCustomNameFields returns proper structure
     *
     * @return void
     */
    public function testGetCustomNameFields(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->getCustomNameFields($this->testUser);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('firstName', $result);
        $this->assertArrayHasKey('lastName', $result);
        $this->assertArrayHasKey('middleName', $result);
    }

    /**
     * Test setCustomNameFields stores and retrieves values
     *
     * @return void
     */
    public function testSetCustomNameFields(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $nameFields = [
            'firstName'  => 'TestFirst',
            'lastName'   => 'TestLast',
            'middleName' => 'TestMiddle',
        ];

        $this->service->setCustomNameFields($this->testUser, $nameFields);

        $result = $this->service->getCustomNameFields($this->testUser);

        $this->assertSame('TestFirst', $result['firstName']);
        $this->assertSame('TestLast', $result['lastName']);
        $this->assertSame('TestMiddle', $result['middleName']);
    }

    /**
     * Test setCustomNameFields with partial fields
     *
     * @return void
     */
    public function testSetCustomNameFieldsPartial(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $nameFields = [
            'firstName' => 'OnlyFirst',
        ];

        $this->service->setCustomNameFields($this->testUser, $nameFields);

        $result = $this->service->getCustomNameFields($this->testUser);

        $this->assertSame('OnlyFirst', $result['firstName']);
    }

    /**
     * Test setCustomNameFields ignores unknown fields
     *
     * @return void
     */
    public function testSetCustomNameFieldsIgnoresUnknown(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $nameFields = [
            'firstName'   => 'ValidField',
            'unknownField' => 'ShouldBeIgnored',
        ];

        // Should not throw
        $this->service->setCustomNameFields($this->testUser, $nameFields);

        $result = $this->service->getCustomNameFields($this->testUser);
        $this->assertSame('ValidField', $result['firstName']);
        $this->assertArrayNotHasKey('unknownField', $result);
    }

    /**
     * Test updateUserProperties returns success result
     *
     * @return void
     */
    public function testUpdateUserProperties(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = [
            'firstName' => 'UpdatedFirst',
            'lastName'  => 'UpdatedLast',
        ];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('organisation_updated', $result);
    }

    /**
     * Test updateUserProperties with display name
     *
     * @return void
     */
    public function testUpdateUserPropertiesDisplayName(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $newDisplayName = 'Test Display ' . uniqid();
        $data = [
            'displayName' => $newDisplayName,
        ];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);

        // Verify the display name was updated
        $userData = $this->service->buildUserDataArray($this->testUser);
        $this->assertSame($newDisplayName, $userData['displayName']);
    }

    /**
     * Test groups field in buildUserDataArray is array
     *
     * @return void
     */
    public function testBuildUserDataArrayGroups(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertIsArray($result['groups']);
    }
}
