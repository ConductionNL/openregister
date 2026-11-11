<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\LeafScriptListener;
use OCA\OpenRegister\Service\Integration\LeafAvailability;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafIdentity;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Who gets another app's leaf bundle on a page: refused for anonymous pages,
 * OpenRegister's own pages and non-app paths before the catalogue is read;
 * a failing catalogue is logged, never thrown. Pinned on the code before the
 * A29 refactor.
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-a-leafs-client-half-must-be-loaded-on-consuming-pages
 */
class LeafScriptListenerGateTest extends TestCase {

	/**
	 * Path => whether the catalogue may be consulted for a logged-in page.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function paths(): array {
		return [
			'openregister page' => ['/apps/openregister/', false],
			'openregister page via index.php' => ['/index.php/apps/openregister/objects', false],
			'not an app page' => ['/settings/user', false],
			'uppercase id is not an app id' => ['/apps/Procest/', false],
			'consuming app' => ['/apps/procest/cases', true],
			'consuming app via index.php' => ['/index.php/apps/procest', true],
		];
	}//end paths()

	/**
	 * A listener over doubles; the catalogue is a spy.
	 *
	 * @param IRequest $request The request.
	 * @param LeafRegistry $registry The catalogue.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return LeafScriptListener The listener.
	 */
	private function listener(IRequest $request, LeafRegistry $registry, LoggerInterface $logger): LeafScriptListener {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willThrowException(new RuntimeException('no path'));

		return new LeafScriptListener($registry, $apps, $request, $logger);
	}//end listener()

	/**
	 * Only a logged-in page of another app reaches the catalogue.
	 *
	 * @param string $path The request path.
	 * @param bool $consulted Whether the app gate lets it through.
	 *
	 * @return void
	 */
	#[DataProvider('paths')]
	public function testOnlyAnotherAppsLoggedInPageGetsThrough(string $path, bool $consulted): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$registry = $this->createMock(LeafRegistry::class);
		$registry->expects($this->never())->method('getDescriptors');
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($consulted === true ? $this->once() : $this->never())->method('getAppPath')
			->with('procest')->willThrowException(new RuntimeException('no path'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new LeafScriptListener($registry, $apps, $request, $logger))
			->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('procest', 'main')));
	}//end testOnlyAnotherAppsLoggedInPageGetsThrough()

	/**
	 * An anonymous page and any other event never read the request.
	 *
	 * @return void
	 */
	public function testAnonymousPagesAndOtherEventsAreRefusedFirst(): void {
		$request = $this->createMock(IRequest::class);
		$request->expects($this->never())->method('getPathInfo');
		$listener = $this->listener(request: $request, registry: $this->createMock(LeafRegistry::class), logger: $this->createMock(LoggerInterface::class));

		$listener->handle(new BeforeTemplateRenderedEvent(false, new TemplateResponse('procest', 'main')));
		$listener->handle(new Event());
	}//end testAnonymousPagesAndOtherEventsAreRefusedFirst()

	/**
	 * A failing request read is logged with the exception, never thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$error = new RuntimeException('request gone');
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willThrowException($error);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('OpenRegister could not enqueue leaf scripts: request gone', ['exception' => $error]);

		$this->listener(request: $request, registry: $this->createMock(LeafRegistry::class), logger: $logger)
			->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse('procest', 'main')));
	}//end testAFailureIsLoggedNotThrown()

	/**
	 * The catalogue rule refuses the empty id and OpenRegister itself before any lookup,
	 * and asks whether a providing app is enabled only for render-surface leaves of other apps.
	 *
	 * @return void
	 */
	public function testLeafAppsForAsksOnlyWhatItMust(): void {
		$root = sys_get_temp_dir() . '/or-leafgate-' . bin2hex(random_bytes(4));
		mkdir($root . '/procest/lib/Settings', 0777, true);
		file_put_contents($root . '/procest/lib/Settings/procest_register.json', '{}');
		$registry = $this->createMock(LeafRegistry::class);
		$registry->method('getDescriptors')->willReturn(
			[
				new LeafDescriptor(identity: new LeafIdentity(id: 'a', label: 'a', icon: 'X'), kinds: ['data-provider'], availability: new LeafAvailability(requiredApp: 'pipelinq')),
				new LeafDescriptor(identity: new LeafIdentity(id: 'b', label: 'b', icon: 'X'), kinds: [LeafDescriptor::KIND_RENDER_SURFACE], availability: new LeafAvailability(requiredApp: null)),
				new LeafDescriptor(identity: new LeafIdentity(id: 'c', label: 'c', icon: 'X'), kinds: [LeafDescriptor::KIND_RENDER_SURFACE], availability: new LeafAvailability(requiredApp: 'procest')),
				new LeafDescriptor(identity: new LeafIdentity(id: 'd', label: 'd', icon: 'X'), kinds: [LeafDescriptor::KIND_RENDER_SURFACE], availability: new LeafAvailability(requiredApp: 'zaakafhandel')),
			]
		);
		$asked = [];
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturnCallback(
			static function (string $app) use ($root): string {
				if (is_dir($root . '/' . $app) === false) {
					throw new RuntimeException('no path');
				}

				return $root . '/' . $app;
			}
		);
		$apps->method('isEnabledForUser')->willReturnCallback(
			static function (string $app) use (&$asked): bool {
				$asked[] = $app;

				return false;
			}
		);
		$listener = new LeafScriptListener($registry, $apps, $this->createMock(IRequest::class), $this->createMock(LoggerInterface::class));

		$this->assertSame([], $listener->leafAppsFor(''));
		$this->assertSame([], $listener->leafAppsFor('openregister'));
		$this->assertSame([], $listener->leafAppsFor('procest'));
		$this->assertSame(['zaakafhandel'], $asked);

		unlink($root . '/procest/lib/Settings/procest_register.json');
		rmdir($root . '/procest/lib/Settings');
		rmdir($root . '/procest/lib');
		rmdir($root . '/procest');
		rmdir($root);
	}//end testLeafAppsForAsksOnlyWhatItMust()
}//end class
