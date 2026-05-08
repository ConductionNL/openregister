<?php

namespace Unit\Sections;

use OCA\OpenRegister\Sections\OpenRegisterAdmin;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpenRegisterAdminTest extends TestCase
{
    private IL10N&MockObject $l10n;
    private IURLGenerator&MockObject $urlGenerator;
    private OpenRegisterAdmin $section;

    protected function setUp(): void
    {
        $this->l10n = $this->createMock(IL10N::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->section = new OpenRegisterAdmin($this->l10n, $this->urlGenerator);
    }

    public function testImplementsIIconSection(): void
    {
        $this->assertInstanceOf(IIconSection::class, $this->section);
    }

    public function testGetIdReturnsOpenregister(): void
    {
        $this->assertSame('openregister', $this->section->getID());
    }

    public function testGetNameUsesTranslation(): void
    {
        $this->l10n->expects($this->once())
            ->method('t')
            ->with('Open Register')
            ->willReturn('Open Register');

        $this->assertSame('Open Register', $this->section->getName());
    }

    public function testGetNameReturnsTranslatedString(): void
    {
        $this->l10n->method('t')
            ->with('Open Register')
            ->willReturn('Register Openen');

        $this->assertSame('Register Openen', $this->section->getName());
    }

    public function testGetPriorityReturns97(): void
    {
        $this->assertSame(97, $this->section->getPriority());
    }

    public function testGetIconUsesUrlGenerator(): void
    {
        $this->urlGenerator->expects($this->once())
            ->method('imagePath')
            ->with('openregister', 'app-dark.svg')
            ->willReturn('/apps/openregister/img/app-dark.svg');

        $this->assertSame('/apps/openregister/img/app-dark.svg', $this->section->getIcon());
    }

    public function testGetIconReturnsUrlGeneratorResult(): void
    {
        $this->urlGenerator->method('imagePath')->willReturn('/custom/path/icon.svg');

        $this->assertSame('/custom/path/icon.svg', $this->section->getIcon());
    }
}
