<?php

/**
 * Reading the credential provider catalogue from the app's own file.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The catalogue is read once from the app path; a missing or malformed file yields no providers.
 *
 * @covers \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 */
final class ProviderCatalogueFileTest extends TestCase {

	private string $appPath;

	private IAppManager&MockObject $apps;

	private LoggerInterface&MockObject $logger;

	/**
	 * A throwaway app directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appPath = sys_get_temp_dir() . '/or-catalogue-' . bin2hex(random_bytes(6));
		mkdir($this->appPath . '/lib/Settings', 0777, true);
		$this->apps = $this->createMock(IAppManager::class);
		$this->apps->method('getAppPath')->with('openregister')->willReturn($this->appPath);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Remove the throwaway directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->appPath . '/lib/Settings/credential-providers.json');
		@rmdir($this->appPath . '/lib/Settings');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Write the catalogue file.
	 *
	 * @param string $contents The file contents.
	 *
	 * @return void
	 */
	private function write(string $contents): void {
		file_put_contents($this->appPath . '/lib/Settings/credential-providers.json', $contents);
	}//end write()

	/**
	 * The catalogue under test.
	 *
	 * @return ProviderCatalogue The catalogue.
	 */
	private function catalogue(): ProviderCatalogue {
		return new ProviderCatalogue(appManager: $this->apps, logger: $this->logger);
	}//end catalogue()

	/**
	 * Providers are read from the file, looked up by id, and read only once.
	 *
	 * @return void
	 */
	public function testProvidersAreReadOnceAndLookedUpById(): void {
		$this->write('{"providers": {"github": {"label": "GitHub"}, "broken": "not an entry"}}');
		$this->logger->expects($this->never())->method('warning');
		$catalogue = $this->catalogue();

		$this->assertSame(['label' => 'GitHub'], $catalogue->get(providerId: 'github'));
		$this->assertNull($catalogue->get(providerId: 'broken'));
		$this->assertNull($catalogue->get(providerId: 'unknown'));

		unlink($this->appPath . '/lib/Settings/credential-providers.json');
		$this->assertSame(['github', 'broken'], array_keys($catalogue->all()));
	}//end testProvidersAreReadOnceAndLookedUpById()

	/**
	 * A missing file yields no providers and a warning naming the path.
	 *
	 * @return void
	 */
	public function testAMissingFileYieldsNoProviders(): void {
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('missing or unreadable'));

		$this->assertSame([], $this->catalogue()->all());
	}//end testAMissingFileYieldsNoProviders()

	/**
	 * A file that is not provider JSON yields no providers and a warning.
	 *
	 * @return void
	 */
	public function testMalformedJsonYieldsNoProviders(): void {
		foreach (['not json', '{"providers": "x"}', '{"other": {}}'] as $contents) {
			$this->write($contents);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('warning')->with($this->stringContains('not valid provider JSON'));

			$this->assertSame([], (new ProviderCatalogue(appManager: $this->apps, logger: $logger))->all(), $contents);
		}
	}//end testMalformedJsonYieldsNoProviders()
}//end class
