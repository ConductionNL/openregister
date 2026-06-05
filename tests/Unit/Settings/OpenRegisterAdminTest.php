<?php

<<<<<<< HEAD
=======
/**
 * OpenRegisterAdminTest
 *
 * Unit tests for the OpenRegisterAdmin settings class.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

>>>>>>> origin/development
declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Settings;

use OCA\OpenRegister\Settings\OpenRegisterAdmin;
<<<<<<< HEAD
use OCP\AppFramework\Http\TemplateResponse;
=======
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
>>>>>>> origin/development
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

<<<<<<< HEAD
class OpenRegisterAdminTest extends TestCase
{
    private OpenRegisterAdmin $admin;
    private IConfig&MockObject $config;
    private IL10N&MockObject $l10n;

    protected function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->admin = new OpenRegisterAdmin($this->config, $this->l10n);
    }

=======
/**
 * Tests for OpenRegisterAdmin settings.
 *
 * @coversDefaultClass \OCA\OpenRegister\Settings\OpenRegisterAdmin
 */
class OpenRegisterAdminTest extends TestCase
{

    private OpenRegisterAdmin $admin;

    /**
     * @var IConfig&MockObject
     */
    private IConfig $config;

    /**
     * @var IL10N&MockObject
     */
    private IL10N $l10n;

    /**
     * @var IAppManager&MockObject
     */
    private IAppManager $appManager;

    /**
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * @var IInitialState&MockObject
     */
    private IInitialState $initialState;

    /**
     * Set up mocks and admin instance before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config       = $this->createMock(IConfig::class);
        $this->l10n         = $this->createMock(IL10N::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->initialState = $this->createMock(IInitialState::class);

        $this->admin = new OpenRegisterAdmin(
            $this->config,
            $this->l10n,
            $this->appManager,
            $this->appConfig,
            $this->initialState
        );
    }//end setUp()

    /**
     * Test that getForm() returns a TemplateResponse for the settings/admin template.
     *
     * @return void
     */
>>>>>>> origin/development
    public function testGetForm(): void
    {
        $this->config->expects($this->once())
            ->method('getSystemValue')
            ->with('open_register_setting', true)
            ->willReturn(true);

<<<<<<< HEAD
=======
        // notify_push not installed — pushStatus = 'not_installed'.
        $this->appManager->method('isInstalled')->with('notify_push')->willReturn(false);
        $this->initialState->expects($this->once())->method('provideInitialState');

>>>>>>> origin/development
        $result = $this->admin->getForm();

        $this->assertInstanceOf(TemplateResponse::class, $result);
        $this->assertSame('settings/admin', $result->getTemplateName());
<<<<<<< HEAD
    }

    public function testGetSection(): void
    {
        $this->assertSame('openregister', $this->admin->getSection());
    }

    public function testGetPriority(): void
    {
        $this->assertSame(11, $this->admin->getPriority());
    }

=======
    }//end testGetForm()

    /**
     * Test that getSection() returns 'openregister'.
     *
     * @return void
     */
    public function testGetSection(): void
    {
        $this->assertSame('openregister', $this->admin->getSection());
    }//end testGetSection()

    /**
     * Test that getPriority() returns 11.
     *
     * @return void
     */
    public function testGetPriority(): void
    {
        $this->assertSame(11, $this->admin->getPriority());
    }//end testGetPriority()

    /**
     * Test getForm() when the system setting is false.
     *
     * @return void
     */
>>>>>>> origin/development
    public function testGetFormWithFalseSetting(): void
    {
        $this->config->method('getSystemValue')
            ->with('open_register_setting', true)
            ->willReturn(false);

<<<<<<< HEAD
        $result = $this->admin->getForm();

        $this->assertInstanceOf(TemplateResponse::class, $result);
    }
}
=======
        $this->appManager->method('isInstalled')->with('notify_push')->willReturn(false);
        $this->initialState->method('provideInitialState');

        $result = $this->admin->getForm();

        $this->assertInstanceOf(TemplateResponse::class, $result);
    }//end testGetFormWithFalseSetting()
}//end class
>>>>>>> origin/development
