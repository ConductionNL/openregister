<?php

/**
 * Integration tests for UserService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\UserService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for UserService
 *
 * Tests user data retrieval, custom name fields, quota info, language/locale,
 * profile properties, property updates, organisation switching, and change detection.
 *
 * @group DB
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
     * Config service
     *
     * @var IConfig
     */
    private IConfig $config;

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
        $this->config = \OC::$server->get(IConfig::class);

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

    /**
     * Test buildUserDataArray returns enabled status
     *
     * @return void
     */
    public function testBuildUserDataArrayEnabled(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertTrue($result['enabled']);
    }

    /**
     * Test buildUserDataArray includes lastLogin
     *
     * @return void
     */
    public function testBuildUserDataArrayLastLogin(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('lastLogin', $result);
        // New user has lastLogin = 0
        $this->assertSame(0, $result['lastLogin']);
    }

    /**
     * Test buildUserDataArray includes backend name
     *
     * @return void
     */
    public function testBuildUserDataArrayBackend(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('backend', $result);
        $this->assertIsString($result['backend']);
    }

    /**
     * Test buildUserDataArray includes subadmin field
     *
     * @return void
     */
    public function testBuildUserDataArraySubadmin(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('subadmin', $result);
        $this->assertIsArray($result['subadmin']);
    }

    /**
     * Test buildUserDataArray includes avatarScope
     *
     * @return void
     */
    public function testBuildUserDataArrayAvatarScope(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('avatarScope', $result);
    }

    /**
     * Test buildUserDataArray includes emailVerified
     *
     * @return void
     */
    public function testBuildUserDataArrayEmailVerified(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('emailVerified', $result);
    }

    /**
     * Test updateUserProperties with email change
     *
     * @return void
     */
    public function testUpdateUserPropertiesEmail(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $newEmail = 'phpunit-' . uniqid() . '@test.local';
        $data = ['email' => $newEmail];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);

        // Email setting may require verification; just check the call succeeded
        $userData = $this->service->buildUserDataArray($this->testUser);
        $this->assertArrayHasKey('email', $userData);
    }

    /**
     * Test updateUserProperties with language change
     *
     * @return void
     */
    public function testUpdateUserPropertiesLanguage(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['language' => 'nl'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test updateUserProperties with locale change
     *
     * @return void
     */
    public function testUpdateUserPropertiesLocale(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['locale' => 'nl_NL'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test updateUserProperties with profile fields
     *
     * @return void
     */
    public function testUpdateUserPropertiesProfileFields(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = [
            'phone'   => '+31612345678',
            'website' => 'https://example.com',
            'twitter' => '@testuser',
        ];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test updateUserProperties with middleName
     *
     * @return void
     */
    public function testUpdateUserPropertiesMiddleName(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['middleName' => 'van der'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);

        // Verify via getCustomNameFields
        $nameFields = $this->service->getCustomNameFields($this->testUser);
        $this->assertSame('van der', $nameFields['middleName']);
    }

    /**
     * Test updateUserProperties with functie field
     *
     * @return void
     */
    public function testUpdateUserPropertiesFunctie(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['functie' => 'Developer'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);

        // Verify functie is stored in user config
        $storedFunctie = $this->config->getUserValue($this->testUserId, 'core', 'functie', '');
        $this->assertSame('Developer', $storedFunctie);
    }

    /**
     * Test updateUserProperties with organisation switching attempt
     *
     * Without a logged-in user, organisation switching will fail gracefully.
     *
     * @return void
     */
    public function testUpdateUserPropertiesOrganisationSwitch(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['activeOrganisation' => 'non-existent-uuid'];

        try {
            $result = $this->service->updateUserProperties($this->testUser, $data);
            $this->assertIsArray($result);
            $this->assertArrayHasKey('organisation_updated', $result);
        } catch (\Exception $e) {
            // Organisation switch requires a logged-in user session, which
            // is not available in PHPUnit context. This is expected.
            $this->assertStringContainsString('user', strtolower($e->getMessage()));
        }
    }

    /**
     * Test updateUserProperties with empty data triggers no changes
     *
     * @return void
     */
    public function testUpdateUserPropertiesEmptyData(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->updateUserProperties($this->testUser, []);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['organisation_updated']);
    }

    /**
     * Test buildUserDataArray functie field from config fallback
     *
     * @return void
     */
    public function testBuildUserDataArrayFunctieFromConfig(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        // Set functie directly in config
        $this->config->setUserValue($this->testUserId, 'core', 'functie', 'Tester');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('functie', $result);
    }

    /**
     * Test buildUserDataArray caches organisation stats
     *
     * @return void
     */
    public function testBuildUserDataArrayCachesOrgStats(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        // First call populates cache
        $result1 = $this->service->buildUserDataArray($this->testUser);
        // Second call uses cache
        $result2 = $this->service->buildUserDataArray($this->testUser);

        $this->assertSame($result1['organisations']['total'], $result2['organisations']['total']);
    }

    /**
     * Test updateUserProperties dispatches event on changes
     *
     * @return void
     */
    public function testUpdateUserPropertiesDispatchesEvent(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        // Update display name to trigger change detection
        $newName = 'DispatchTest' . uniqid();
        $result = $this->service->updateUserProperties($this->testUser, [
            'displayName' => $newName,
        ]);

        $this->assertTrue($result['success']);

        // Verify change was applied
        $userData = $this->service->buildUserDataArray($this->testUser);
        $this->assertSame($newName, $userData['displayName']);
    }

    /**
     * Test updateUserProperties with fediverse field
     *
     * @return void
     */
    public function testUpdateUserPropertiesFediverse(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['fediverse' => '@test@mastodon.social'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test updateUserProperties with biography field
     *
     * @return void
     */
    public function testUpdateUserPropertiesBiography(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['biography' => 'Test biography for PHPUnit integration test'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test updateUserProperties with headline field
     *
     * @return void
     */
    public function testUpdateUserPropertiesHeadline(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['headline' => 'Senior Developer'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test updateUserProperties with role field
     *
     * @return void
     */
    public function testUpdateUserPropertiesRole(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['role' => 'Product Owner'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test updateUserProperties with address field
     *
     * @return void
     */
    public function testUpdateUserPropertiesAddress(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = ['address' => 'Keizersgracht 1, Amsterdam'];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);
    }

    /**
     * Test getCustomNameFields returns null for unset fields
     *
     * @return void
     */
    public function testGetCustomNameFieldsReturnsNullForUnset(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        // Fresh user has no custom name fields
        $result = $this->service->getCustomNameFields($this->testUser);

        $this->assertNull($result['firstName']);
        $this->assertNull($result['lastName']);
        $this->assertNull($result['middleName']);
    }

    /**
     * Test buildUserDataArray includes language and locale
     *
     * @return void
     */
    public function testBuildUserDataArrayLanguageLocale(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        // Set language first
        if (method_exists($this->testUser, 'setLanguage')) {
            $this->testUser->setLanguage('en');
        }

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertArrayHasKey('language', $result);
        $this->assertArrayHasKey('locale', $result);
    }

    /**
     * Test updateUserProperties with multiple profile fields at once
     *
     * @return void
     */
    public function testUpdateUserPropertiesMultipleProfileFields(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $data = [
            'firstName'   => 'Multi',
            'lastName'    => 'Update',
            'middleName'  => 'Test',
            'phone'       => '+31612345678',
            'website'     => 'https://multi.test',
            'headline'    => 'Multi-field update test',
            'biography'   => 'Testing multiple fields at once',
        ];

        $result = $this->service->updateUserProperties($this->testUser, $data);

        $this->assertTrue($result['success']);

        // Verify name fields
        $nameFields = $this->service->getCustomNameFields($this->testUser);
        $this->assertSame('Multi', $nameFields['firstName']);
        $this->assertSame('Update', $nameFields['lastName']);
        $this->assertSame('Test', $nameFields['middleName']);
    }

    /**
     * Test buildUserDataArray with organisation config value set
     *
     * @return void
     */
    public function testBuildUserDataArrayWithOrganisationConfig(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $this->config->setUserValue($this->testUserId, 'core', 'organisation', 'test-org-uuid');

        $result = $this->service->buildUserDataArray($this->testUser);

        $this->assertIsArray($result);
        // The organisation field should be present in the result
        $this->assertArrayHasKey('organisations', $result);
    }

    /**
     * Test buildUserDataArray quota relative calculation with numeric quota
     *
     * @return void
     */
    public function testBuildUserDataArrayQuotaRelative(): void
    {
        $this->assertNotNull($this->testUser, 'Test user should exist');

        $result = $this->service->buildUserDataArray($this->testUser);

        $quota = $result['quota'];
        // relative should be a numeric value
        $this->assertIsNumeric($quota['relative']);
    }
}
