<?php

/**
 * AppHost GenericDeepLinkRegistrationListener — manifest reading and
 * registration tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Listener;

use OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Dto\DeepLinkRegistration;

/**
 * Each manifest-declared deep link reaches the registry; anything missing,
 * malformed or incomplete registers nothing and never throws.
 */
class GenericDeepLinkRegistrationListenerBehaviourTest extends TestCase {
	private string $appDir;

	protected function setUp(): void {
		$this->appDir = sys_get_temp_dir() . '/a7-deeplink-' . bin2hex(random_bytes(6));
		mkdir($this->appDir . '/src', 0777, true);
	}

	protected function tearDown(): void {
		@unlink($this->appDir . '/src/manifest.json');
		@rmdir($this->appDir . '/src');
		@rmdir($this->appDir);
	}

	private function writeManifest(string $raw): void {
		file_put_contents($this->appDir . '/src/manifest.json', $raw);
	}

	private function appManager(): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('leafapp')->willReturn($this->appDir);
		return $appManager;
	}

	private function listener(?IAppManager $appManager = null, ?LoggerInterface $logger = null): GenericDeepLinkRegistrationListener {
		return new GenericDeepLinkRegistrationListener(
			'leafapp',
			($appManager ?? $this->appManager()),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}

	public function testRegistersCompleteEntriesAndSkipsIncompleteOnes(): void {
		$this->writeManifest((string)json_encode([
			'deepLinks' => [
				['registerSlug' => 'petstore', 'schemaSlug' => 'pet', 'urlTemplate' => '/apps/leafapp/#/pets/{uuid}', 'icon' => 'paw', 'displayName' => 'Pet'],
				['registerSlug' => 'petstore', 'schemaSlug' => '', 'urlTemplate' => '/x'],
				['registerSlug' => '', 'schemaSlug' => 'pet', 'urlTemplate' => '/x'],
				['registerSlug' => 'petstore', 'schemaSlug' => 'pet'],
				'not-an-entry',
				['registerSlug' => 'petstore', 'schemaSlug' => 'owner', 'urlTemplate' => '/apps/leafapp/#/owners/{uuid}', 'displayName' => 42],
			],
		]));

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$calls = [];
		$registry->expects($this->exactly(2))->method('add')
			->willReturnCallback(function (DeepLinkRegistration $link) use (&$calls): void {
				$calls[] = [$link->appId, $link->registerSlug, $link->schemaSlug, $link->urlTemplate, $link->icon, $link->displayName];
			});

		$this->listener()->handle(new DeepLinkRegistrationEvent($registry));

		$this->assertSame(
			[
				['leafapp', 'petstore', 'pet', '/apps/leafapp/#/pets/{uuid}', 'paw', 'Pet'],
				['leafapp', 'petstore', 'owner', '/apps/leafapp/#/owners/{uuid}', '', null],
			],
			$calls
		);
	}

	public function testIgnoresUnrelatedEvents(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->never())->method('getAppPath');

		$this->listener($appManager)->handle(new Event());
	}

	public function testUnknownAppPathRegistersNothing(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException('leafapp'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$registry->expects($this->never())->method('add');

		$this->listener($appManager, $logger)->handle(new DeepLinkRegistrationEvent($registry));
	}

	public function testMissingManifestRegistersNothingAndWarns(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('[AppHost:leafapp] no src/manifest.json'));

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$registry->expects($this->never())->method('add');

		$this->listener(null, $logger)->handle(new DeepLinkRegistrationEvent($registry));
	}

	public function testInvalidJsonRegistersNothingAndWarns(): void {
		$this->writeManifest('{"deepLinks": [');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('manifest.json is invalid JSON'));

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$registry->expects($this->never())->method('add');

		$this->listener(null, $logger)->handle(new DeepLinkRegistrationEvent($registry));
	}

	public function testScalarJsonManifestIsTreatedAsInvalid(): void {
		$this->writeManifest('"just a string"');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('invalid JSON'));

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$registry->expects($this->never())->method('add');

		$this->listener(null, $logger)->handle(new DeepLinkRegistrationEvent($registry));
	}

	public function testNonArrayDeepLinksBlockRegistersNothingSilently(): void {
		$this->writeManifest('{"deepLinks": "petstore"}');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$registry->expects($this->never())->method('add');

		$this->listener(null, $logger)->handle(new DeepLinkRegistrationEvent($registry));
	}

	public function testManifestWithoutDeepLinksBlockRegistersNothing(): void {
		$this->writeManifest('{"name": "leafapp"}');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$registry->expects($this->never())->method('add');

		$this->listener(null, $logger)->handle(new DeepLinkRegistrationEvent($registry));
	}
}
