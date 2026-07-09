<?php

/**
 * ProviderCatalogueTest — unit tests for the read-only provider catalogue loader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 */
class ProviderCatalogueTest extends TestCase
{
    private ProviderCatalogue $catalogue;

    protected function setUp(): void
    {
        // Repo root = four levels up from tests/Unit/Service/Credential.
        $appRoot    = dirname(__DIR__, 4);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn($appRoot);

        $this->catalogue = new ProviderCatalogue(
            $appManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testLoadsGithubEntry(): void
    {
        $github = $this->catalogue->get('github');
        $this->assertIsArray($github);
        $this->assertSame('https://api.github.com', $github['baseUrl']);
        $this->assertSame('Authorization', $github['authScheme']['header']);
        $this->assertStringContainsString('{secret}', $github['authScheme']['template']);
    }

    public function testLoadsGitlabEntry(): void
    {
        $gitlab = $this->catalogue->get('gitlab');
        $this->assertIsArray($gitlab);
        $this->assertSame('https://gitlab.com/api/v4', $gitlab['baseUrl']);
        $this->assertStringContainsString('Bearer', $gitlab['authScheme']['template']);
    }

    public function testUnknownProviderReturnsNull(): void
    {
        $this->assertNull($this->catalogue->get('does-not-exist'));
    }

    public function testAllReturnsBothProviders(): void
    {
        $all = $this->catalogue->all();
        $this->assertArrayHasKey('github', $all);
        $this->assertArrayHasKey('gitlab', $all);
    }
}//end class
