<?php

/**
 * Activity Settings Unit Test
 *
 * Tests all three ActivitySettings subclasses.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Activity\Setting
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Activity\Setting;

use OCA\OpenRegister\Activity\Setting\ObjectSetting;
use OCA\OpenRegister\Activity\Setting\RegisterSetting;
use OCA\OpenRegister\Activity\Setting\SchemaSetting;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Activity Settings.
 */
class ObjectSettingTest extends TestCase
{
    private IL10N $l;

    protected function setUp(): void
    {
        parent::setUp();
        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);
    }

    /**
     * Test: ObjectSetting has correct identifier and defaults.
     */
    public function testObjectSettingIdentifierAndDefaults(): void
    {
        $setting = new ObjectSetting($this->l);
        $this->assertSame('openregister_objects', $setting->getIdentifier());
        $this->assertSame('Object changes', $setting->getName());
        $this->assertSame('openregister', $setting->getGroupIdentifier());
        $this->assertSame('Open Register', $setting->getGroupName());
        $this->assertSame(51, $setting->getPriority());
        $this->assertTrue($setting->canChangeStream());
        $this->assertTrue($setting->isDefaultEnabledStream());
        $this->assertTrue($setting->canChangeMail());
        $this->assertFalse($setting->isDefaultEnabledMail());
    }

    /**
     * Test: RegisterSetting has correct identifier.
     */
    public function testRegisterSettingIdentifier(): void
    {
        $setting = new RegisterSetting($this->l);
        $this->assertSame('openregister_registers', $setting->getIdentifier());
        $this->assertSame('Register changes', $setting->getName());
        $this->assertSame(52, $setting->getPriority());
    }

    /**
     * Test: SchemaSetting has correct identifier.
     */
    public function testSchemaSettingIdentifier(): void
    {
        $setting = new SchemaSetting($this->l);
        $this->assertSame('openregister_schemas', $setting->getIdentifier());
        $this->assertSame('Schema changes', $setting->getName());
        $this->assertSame(53, $setting->getPriority());
    }

    /**
     * Test: All settings share the same group identifier.
     */
    public function testAllSettingsShareGroup(): void
    {
        $objectSetting   = new ObjectSetting($this->l);
        $registerSetting = new RegisterSetting($this->l);
        $schemaSetting   = new SchemaSetting($this->l);

        $this->assertSame($objectSetting->getGroupIdentifier(), $registerSetting->getGroupIdentifier());
        $this->assertSame($objectSetting->getGroupIdentifier(), $schemaSetting->getGroupIdentifier());
    }
}
