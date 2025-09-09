<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Db\SettingsMapper;
use OCA\OpenRegister\Db\Settings;
use PHPUnit\Framework\TestCase;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Test class for SettingsService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class SettingsServiceTest extends TestCase
{
    private SettingsService $settingsService;
    private SettingsMapper $settingsMapper;
    private IUserSession $userSession;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->settingsMapper = $this->createMock(SettingsMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);

        // Create SettingsService instance
        $this->settingsService = new SettingsService(
            $this->settingsMapper,
            $this->userSession
        );
    }

    /**
     * Test getSetting method with existing setting
     */
    public function testGetSettingWithExistingSetting(): void
    {
        $key = 'test_setting';
        $value = 'test_value';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock settings entity
        $settings = $this->createMock(Settings::class);
        $settings->method('getValue')->willReturn($value);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willReturn($settings);

        $result = $this->settingsService->getSetting($key);

        $this->assertEquals($value, $result);
    }

    /**
     * Test getSetting method with non-existent setting
     */
    public function testGetSettingWithNonExistentSetting(): void
    {
        $key = 'non_existent_setting';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper to throw exception
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Setting not found'));

        $result = $this->settingsService->getSetting($key);

        $this->assertNull($result);
    }

    /**
     * Test getSetting method with no user session
     */
    public function testGetSettingWithNoUserSession(): void
    {
        $key = 'test_setting';

        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->settingsService->getSetting($key);

        $this->assertNull($result);
    }

    /**
     * Test getSetting method with default value
     */
    public function testGetSettingWithDefaultValue(): void
    {
        $key = 'test_setting';
        $defaultValue = 'default_value';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper to throw exception
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Setting not found'));

        $result = $this->settingsService->getSetting($key, $defaultValue);

        $this->assertEquals($defaultValue, $result);
    }

    /**
     * Test setSetting method with valid data
     */
    public function testSetSettingWithValidData(): void
    {
        $key = 'test_setting';
        $value = 'test_value';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock settings entity
        $settings = $this->createMock(Settings::class);
        $settings->method('setKey')->with($key);
        $settings->method('setValue')->with($value);
        $settings->method('setUserId')->with($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Setting not found'));

        $this->settingsMapper->expects($this->once())
            ->method('insert')
            ->willReturn($settings);

        $result = $this->settingsService->setSetting($key, $value);

        $this->assertEquals($settings, $result);
    }

    /**
     * Test setSetting method with existing setting (update)
     */
    public function testSetSettingWithExistingSetting(): void
    {
        $key = 'test_setting';
        $value = 'updated_value';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock settings entity
        $settings = $this->createMock(Settings::class);
        $settings->method('setValue')->with($value);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willReturn($settings);

        $this->settingsMapper->expects($this->once())
            ->method('update')
            ->with($settings)
            ->willReturn($settings);

        $result = $this->settingsService->setSetting($key, $value);

        $this->assertEquals($settings, $result);
    }

    /**
     * Test setSetting method with no user session
     */
    public function testSetSettingWithNoUserSession(): void
    {
        $key = 'test_setting';
        $value = 'test_value';

        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->settingsService->setSetting($key, $value);

        $this->assertNull($result);
    }

    /**
     * Test deleteSetting method with existing setting
     */
    public function testDeleteSettingWithExistingSetting(): void
    {
        $key = 'test_setting';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock settings entity
        $settings = $this->createMock(Settings::class);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willReturn($settings);

        $this->settingsMapper->expects($this->once())
            ->method('delete')
            ->with($settings)
            ->willReturn($settings);

        $result = $this->settingsService->deleteSetting($key);

        $this->assertEquals($settings, $result);
    }

    /**
     * Test deleteSetting method with non-existent setting
     */
    public function testDeleteSettingWithNonExistentSetting(): void
    {
        $key = 'non_existent_setting';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper to throw exception
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Setting not found'));

        $result = $this->settingsService->deleteSetting($key);

        $this->assertNull($result);
    }

    /**
     * Test deleteSetting method with no user session
     */
    public function testDeleteSettingWithNoUserSession(): void
    {
        $key = 'test_setting';

        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->settingsService->deleteSetting($key);

        $this->assertNull($result);
    }

    /**
     * Test getAllSettings method
     */
    public function testGetAllSettings(): void
    {
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock settings entities
        $settings1 = $this->createMock(Settings::class);
        $settings1->method('getKey')->willReturn('setting1');
        $settings1->method('getValue')->willReturn('value1');

        $settings2 = $this->createMock(Settings::class);
        $settings2->method('getKey')->willReturn('setting2');
        $settings2->method('getValue')->willReturn('value2');

        $settingsArray = [$settings1, $settings2];

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper
        $this->settingsMapper->expects($this->once())
            ->method('findAllByUser')
            ->with($userId)
            ->willReturn($settingsArray);

        $result = $this->settingsService->getAllSettings();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('value1', $result['setting1']);
        $this->assertEquals('value2', $result['setting2']);
    }

    /**
     * Test getAllSettings method with no user session
     */
    public function testGetAllSettingsWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->settingsService->getAllSettings();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getAllSettings method with no settings
     */
    public function testGetAllSettingsWithNoSettings(): void
    {
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper to return empty array
        $this->settingsMapper->expects($this->once())
            ->method('findAllByUser')
            ->with($userId)
            ->willReturn([]);

        $result = $this->settingsService->getAllSettings();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test hasSetting method with existing setting
     */
    public function testHasSettingWithExistingSetting(): void
    {
        $key = 'test_setting';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Create mock settings entity
        $settings = $this->createMock(Settings::class);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willReturn($settings);

        $result = $this->settingsService->hasSetting($key);

        $this->assertTrue($result);
    }

    /**
     * Test hasSetting method with non-existent setting
     */
    public function testHasSettingWithNonExistentSetting(): void
    {
        $key = 'non_existent_setting';
        $userId = 'testuser';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock settings mapper to throw exception
        $this->settingsMapper->expects($this->once())
            ->method('findByKeyAndUser')
            ->with($key, $userId)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Setting not found'));

        $result = $this->settingsService->hasSetting($key);

        $this->assertFalse($result);
    }

    /**
     * Test hasSetting method with no user session
     */
    public function testHasSettingWithNoUserSession(): void
    {
        $key = 'test_setting';

        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->settingsService->hasSetting($key);

        $this->assertFalse($result);
    }
}
