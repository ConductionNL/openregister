<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Settings;

use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

    public function testGetForm(): void
    {
        $this->config->expects($this->once())
            ->method('getSystemValue')
            ->with('open_register_setting', true)
            ->willReturn(true);

        $result = $this->admin->getForm();

        $this->assertInstanceOf(TemplateResponse::class, $result);
        $this->assertSame('settings/admin', $result->getTemplateName());
    }

    public function testGetSection(): void
    {
        $this->assertSame('openregister', $this->admin->getSection());
    }

    public function testGetPriority(): void
    {
        $this->assertSame(11, $this->admin->getPriority());
    }

    public function testGetFormWithFalseSetting(): void
    {
        $this->config->method('getSystemValue')
            ->with('open_register_setting', true)
            ->willReturn(false);

        $result = $this->admin->getForm();

        $this->assertInstanceOf(TemplateResponse::class, $result);
    }
}
