<?php

/**
 * Regression: deep links missing outside a booted request, and in silence.
 *
 * Seen on cloud.conduction.nl on 9 October 2026: pipelinq notifications fell
 * back to OpenRegister because the deep link registry held no pipelinq route.
 * Two gaps let that happen:
 * - the registry was filled only by OpenRegister's boot(), so a process that
 *   never boots OpenRegister (`occ background-job:worker` loads no apps) read
 *   an empty registry;
 * - pipelinq's deep links live in `src/manifest.json`, which the release
 *   package leaves out, and the listener returned nothing without a word.
 *
 * The registry, the event and pipelinq's listener
 * (GenericDeepLinkRegistrationListener) are real, reading a real manifest file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/deep-link-registry/spec.md#requirement-the-registry-must-fill-itself-in-a-process-that-never-booted-openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks that the registry fills itself and that a missing manifest is reported.
 */
class DeepLinkRegistryBackgroundTest extends TestCase {

	private string $appDir;

	/**
	 * Warnings the listener logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	protected function setUp(): void {
		DeepLinkRegistryService::reset();
		$this->warnings = [];
		$this->appDir = sys_get_temp_dir() . '/or-deeplink-' . bin2hex(random_bytes(4));
		mkdir($this->appDir);
	}//end setUp()

	protected function tearDown(): void {
		DeepLinkRegistryService::reset();
		if (is_file($this->appDir . '/src/manifest.json') === true) {
			unlink($this->appDir . '/src/manifest.json');
			rmdir($this->appDir . '/src');
		}

		rmdir($this->appDir);
	}//end tearDown()

	/**
	 * Write a pipelinq manifest with its client deep link into the app dir.
	 *
	 * @return void
	 */
	private function shipManifest(): void {
		mkdir($this->appDir . '/src');
		file_put_contents(
			$this->appDir . '/src/manifest.json',
			json_encode(
				[
					'deepLinks' => [
						['registerSlug' => 'pipelinq', 'schemaSlug' => 'client', 'urlTemplate' => '/apps/pipelinq/clients/{uuid}'],
					],
				]
			)
		);
	}//end shipManifest()

	/**
	 * pipelinq's listener, as pipelinq's register() builds it.
	 *
	 * @return GenericDeepLinkRegistrationListener The listener.
	 */
	private function makeListener(): GenericDeepLinkRegistrationListener {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string) $message;
			}
		);

		return new GenericDeepLinkRegistrationListener(appId: 'pipelinq', appManager: $appManager, logger: $logger);
	}//end makeListener()

	/**
	 * A registry in a process where OpenRegister's boot() never ran.
	 *
	 * The event dispatcher double hands every event to pipelinq's listener,
	 * as Nextcloud's dispatcher does for a listener registered in register().
	 *
	 * @param integer $dispatched Counts dispatches.
	 *
	 * @return DeepLinkRegistryService The registry.
	 */
	private function makeUnbootedRegistry(int &$dispatched): DeepLinkRegistryService {
		$listener = $this->makeListener();
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener, &$dispatched): void {
				$dispatched++;
				$listener->handle($event);
			}
		);

		$register = new Register();
		$register->setId(20);
		$register->setSlug('pipelinq');
		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('client');
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$schema]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				RegisterMapper::class => $registerMapper,
				SchemaMapper::class => $schemaMapper,
				IEventDispatcher::class => $events,
				default => null,
			}
		);

		return new DeepLinkRegistryService($container, $this->createMock(LoggerInterface::class));
	}//end makeUnbootedRegistry()

	public function testAnUnbootedProcessStillResolvesTheAppsDeepLink(): void {
		$this->shipManifest();
		$dispatched = 0;
		$registry = $this->makeUnbootedRegistry($dispatched);

		$this->assertSame(
			'/apps/pipelinq/clients/c-1',
			$registry->resolveUrl(registerId: 20, schemaId: 42, objectData: ['uuid' => 'c-1'])
		);
		$this->assertSame(1, $dispatched);
		$this->assertSame([], $this->warnings);
	}//end testAnUnbootedProcessStillResolvesTheAppsDeepLink()

	public function testABootedProcessDoesNotAskTwice(): void {
		$this->shipManifest();
		$dispatched = 0;
		$registry = $this->makeUnbootedRegistry($dispatched);

		// What Application::boot() does.
		$registry->requestRegistrations();
		$registry->resolveUrl(registerId: 20, schemaId: 42, objectData: ['uuid' => 'c-1']);
		$registry->resolveUrl(registerId: 20, schemaId: 99, objectData: ['uuid' => 'c-2']);

		$this->assertSame(1, $dispatched);
	}//end testABootedProcessDoesNotAskTwice()

	public function testAPackageWithoutAManifestIsReported(): void {
		// A release package: no src/ directory at all.
		$dispatched = 0;
		$registry = $this->makeUnbootedRegistry($dispatched);

		$this->assertNull($registry->resolveUrl(registerId: 20, schemaId: 42, objectData: ['uuid' => 'c-1']));
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('pipelinq', $this->warnings[0]);
		$this->assertStringContainsString('src/manifest.json', $this->warnings[0]);
	}//end testAPackageWithoutAManifestIsReported()
}//end class
