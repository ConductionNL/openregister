<?php

declare(strict_types=1);

/**
 * Pins GenericInitializeSettings::manifestDescription(): the description a
 * leaf app's bundled manifest declares, or none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Repair\GenericInitializeSettings;
use OCA\OpenRegister\AppHost\Service\GenericSettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The manifest description, for every manifest state.
 */
class GenericInitializeSettingsDescriptionTest extends TestCase {

	/**
	 * Temporary app directory.
	 *
	 * @var string
	 */
	private string $appDir = '';

	protected function tearDown(): void {
		@unlink($this->appDir . '/src/manifest.json');
		@rmdir($this->appDir . '/src');
		@rmdir($this->appDir);
	}//end tearDown()

	/**
	 * Manifest content (null for none) → description.
	 *
	 * @return array<string, array{0: string|null, 1: string|null}>
	 */
	public static function manifests(): array {
		return [
			'no manifest' => [null, null],
			'not json' => ['{nope', null],
			'json but not an object' => ['"text"', null],
			'no description' => ['{"name":"x"}', null],
			'empty description' => ['{"description":""}', null],
			'non-string description' => ['{"description":5}', null],
			'a description' => ['{"description":"Pet Store demo"}', 'Pet Store demo'],
		];
	}//end manifests()

	/**
	 * @dataProvider manifests
	 *
	 * @param string|null $manifest The bundled manifest.
	 * @param string|null $expected The description.
	 *
	 * @return void
	 */
	public function testTheManifestDescription(?string $manifest, ?string $expected): void {
		$this->appDir = sys_get_temp_dir() . '/or-desc-' . bin2hex(random_bytes(6));
		mkdir($this->appDir . '/src', 0777, true);
		if ($manifest !== null) {
			file_put_contents($this->appDir . '/src/manifest.json', $manifest);
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);

		$step = new GenericInitializeSettings(
			'demoapp',
			$this->createMock(GenericSettingsService::class),
			$this->createMock(LoggerInterface::class),
			$appManager
		);

		$method = new \ReflectionMethod(GenericInitializeSettings::class, 'manifestDescription');
		$method->setAccessible(true);
		$this->assertSame($expected, $method->invoke($step));
	}//end testTheManifestDescription()

	public function testAnUnknownAppPathHasNoDescription(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new \RuntimeException('no app'));

		$step   = new GenericInitializeSettings('demoapp', $this->createMock(GenericSettingsService::class), $this->createMock(LoggerInterface::class), $appManager);
		$method = new \ReflectionMethod(GenericInitializeSettings::class, 'manifestDescription');
		$method->setAccessible(true);
		$this->assertNull($method->invoke($step));
	}//end testAnUnknownAppPathHasNoDescription()
}//end class
