<?php

/**
 * LeafScriptListener Unit Test
 *
 * The rule that decides which providing apps' leaf bundles belong on a given
 * consuming page. Every negative here is a way the feature was silently absent
 * before, or a way it could become a regression:
 *
 *   - no bundle built  → enqueuing it would 404 in the consuming page
 *   - app disabled     → a script for an app that is not there
 *   - the app's own page → a second copy of a leaf id already registered
 *   - a non-consuming app → a sibling SPA bundle on the Files page
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\LeafScriptListener;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCP\App\IAppManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LeafScriptListenerTest extends TestCase {
	/** @var string Root of the fake apps directory for this test. */
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/or-leafscripts-' . uniqid();
		mkdir($this->root, 0777, true);
	}

	protected function tearDown(): void {
		if (is_dir($this->root) === false) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $path) {
			$path->isDir() === true ? rmdir($path->getPathname()) : unlink($path->getPathname());
		}
		rmdir($this->root);
	}

	/**
	 * Lay out a fake app on disk.
	 *
	 * @param string  $appId    The app.
	 * @param boolean $register Whether it ships lib/Settings/<app>_register.json.
	 * @param boolean $bundle   Whether it ships js/<app>-leaves.js.
	 *
	 * @return void
	 */
	private function makeApp(string $appId, bool $register, bool $bundle): void {
		$base = $this->root . '/' . $appId;
		mkdir($base . '/lib/Settings', 0777, true);
		mkdir($base . '/js', 0777, true);
		if ($register === true) {
			file_put_contents($base . '/lib/Settings/' . $appId . '_register.json', '{}');
		}
		if ($bundle === true) {
			file_put_contents($base . '/js/' . $appId . '-leaves.js', '// built');
		}
	}

	/**
	 * @param LeafDescriptor[] $descriptors Leaves the registry returns.
	 * @param string[]         $enabled     Apps reported enabled.
	 *
	 * @return LeafScriptListener The listener under test.
	 */
	private function listener(array $descriptors, array $enabled): LeafScriptListener {
		$registry = $this->createMock(LeafRegistry::class);
		$registry->method('getDescriptors')->willReturn($descriptors);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')
			->willReturnCallback(fn (string $app): bool => in_array($app, $enabled, true));
		$appManager->method('getAppPath')
			->willReturnCallback(function (string $app): string {
				$path = $this->root . '/' . $app;
				if (is_dir($path) === false) {
					// What IAppManager really does for an unknown app.
					throw new \OCP\App\AppPathNotFoundException($app);
				}
				return $path;
			});

		return new LeafScriptListener(
			$registry,
			$appManager,
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * @param string      $id   Leaf id.
	 * @param string|null $app  requiredApp.
	 * @param string[]    $kinds Leaf kinds.
	 *
	 * @return LeafDescriptor The descriptor.
	 */
	private function leaf(string $id, ?string $app, array $kinds = [LeafDescriptor::KIND_RENDER_SURFACE]): LeafDescriptor {
		return new LeafDescriptor(
			id: $id,
			label: $id,
			icon: 'FolderOutline',
			kinds: $kinds,
			requiredApp: $app,
		);
	}

	public function testLoadsAProvidingAppsBundleOnAConsumingPage(): void {
		$this->makeApp('pipelinq', register: true, bundle: false);
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[$this->leaf('planninq-projects', 'planninq')],
			['planninq', 'pipelinq']
		);

		$this->assertSame(['planninq'], $listener->leafAppsFor('pipelinq'));
	}

	/**
	 * The single most likely regression: the entry gets renamed or the build
	 * drops it, and enqueuing it would put a 404 in someone else's page.
	 */
	public function testSkipsAProvidingAppWithNoBuiltLeafBundle(): void {
		$this->makeApp('pipelinq', register: true, bundle: false);
		$this->makeApp('planninq', register: true, bundle: false);

		$listener = $this->listener(
			[$this->leaf('planninq-projects', 'planninq')],
			['planninq', 'pipelinq']
		);

		$this->assertSame([], $listener->leafAppsFor('pipelinq'));
	}

	public function testSkipsADisabledProvidingApp(): void {
		$this->makeApp('pipelinq', register: true, bundle: false);
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[$this->leaf('planninq-projects', 'planninq')],
			['pipelinq']
		);

		$this->assertSame([], $listener->leafAppsFor('pipelinq'));
	}

	/**
	 * An app's own bundle already registers its leaf. A second copy would
	 * register the id twice, which AD-13 warns about in production and throws
	 * on in development.
	 */
	public function testNeverLoadsAnAppsOwnLeafOnItsOwnPage(): void {
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[$this->leaf('planninq-projects', 'planninq')],
			['planninq']
		);

		$this->assertSame([], $listener->leafAppsFor('planninq'));
	}

	/**
	 * Files, Photos, Mail and Settings host no OpenRegister objects, so a
	 * sibling bundle there is pure weight.
	 */
	public function testSkipsAppsThatShipNoRegisterDescriptor(): void {
		$this->makeApp('files', register: false, bundle: false);
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[$this->leaf('planninq-projects', 'planninq')],
			['planninq', 'files']
		);

		$this->assertSame([], $listener->leafAppsFor('files'));
	}

	public function testSkipsAnAppItCannotResolveAPathFor(): void {
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[$this->leaf('planninq-projects', 'planninq')],
			['planninq', 'ghost']
		);

		$this->assertSame([], $listener->leafAppsFor('ghost'));
	}

	/**
	 * A data-provider leaf serves data server-side; it has no client half, so
	 * there is nothing to load and loading the app's bundle would be waste.
	 */
	public function testIgnoresLeavesThatAreNotRenderSurfaces(): void {
		$this->makeApp('pipelinq', register: true, bundle: false);
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[$this->leaf('planninq-data', 'planninq', [LeafDescriptor::KIND_DATA_PROVIDER])],
			['planninq', 'pipelinq']
		);

		$this->assertSame([], $listener->leafAppsFor('pipelinq'));
	}

	/**
	 * Built-in leaves declare no requiredApp — they ride on OpenRegister's own
	 * bundle, which the global bootstrap listener already loads everywhere.
	 */
	public function testIgnoresBuiltInLeaves(): void {
		$this->makeApp('pipelinq', register: true, bundle: false);

		$listener = $this->listener([$this->leaf('files', null)], ['pipelinq']);

		$this->assertSame([], $listener->leafAppsFor('pipelinq'));
	}

	/**
	 * One app may contribute several leaves; its bundle carries all of them and
	 * must be enqueued once, or the page loads the same script twice.
	 */
	public function testEnqueuesEachProvidingAppOnlyOnce(): void {
		$this->makeApp('pipelinq', register: true, bundle: false);
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[
				$this->leaf('planninq-projects', 'planninq'),
				$this->leaf('planninq-tasks', 'planninq'),
			],
			['planninq', 'pipelinq']
		);

		$this->assertSame(['planninq'], $listener->leafAppsFor('pipelinq'));
	}

	public function testOpenRegistersOwnPagesLoadNothing(): void {
		$this->makeApp('openregister', register: true, bundle: true);
		$this->makeApp('planninq', register: true, bundle: true);

		$listener = $this->listener(
			[$this->leaf('planninq-projects', 'planninq')],
			['planninq', 'openregister']
		);

		$this->assertSame([], $listener->leafAppsFor('openregister'));
	}
}
