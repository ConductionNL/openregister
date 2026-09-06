<?php

/**
 * Every flow node the palette offers names an icon that exists — and a node
 * whose icon does not resolve is reported, not deleted.
 *
 * WHY BOTH HALVES. `IURLGenerator::imagePath()` THROWS for an image the server
 * does not ship, and `FlowNodeRegistry::palette()` caught that with everything
 * else and left the node out of the catalogue. A node that is not in the
 * catalogue cannot be added to a flow from the editor at all, and nothing said
 * so: `openregister.lock-object` and `openregister.unlock-object` shipped
 * naming `core/img/actions/lock.svg` and `.../unlock.svg`, which no supported
 * Nextcloud has, and both nodes were simply absent. So this file asserts the
 * inventory (no node names an image that is missing) AND the behaviour (a node
 * whose icon throws stays in the palette and the failure is logged), because
 * the inventory alone would leave the next such node to vanish the same way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A node whose metadata answers whatever the test needs.
 */
class PaletteProbeNode implements IFlowNode {

	/**
	 * Constructor.
	 *
	 * @param string $icon The icon to return, or '' to throw as imagePath() does.
	 */
	public function __construct(private readonly string $icon) {
	}

	/**
	 * The type id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'test.probe';
	}

	/**
	 * Accepts any configuration.
	 *
	 * @param array $config The config.
	 *
	 * @return void
	 */
	public function validateConfig(array $config): void {
	}

	/**
	 * The display name.
	 *
	 * @return string The name.
	 */
	public function getDisplayName(): string {
		return 'Probe';
	}

	/**
	 * The description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return 'A node that exists only in this test.';
	}

	/**
	 * The icon — or the throw a missing image produces.
	 *
	 * @return string The icon path.
	 */
	public function getIcon(): string {
		if ($this->icon === '') {
			// The shape IURLGenerator::imagePath() throws with.
			throw new RuntimeException('image not found: image:actions/lock.svg webroot:/ serverroot:/var/www/html');
		}

		return $this->icon;
	}

	/**
	 * Available everywhere.
	 *
	 * @param int $scope The scope.
	 *
	 * @return bool True.
	 */
	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	/**
	 * Execute.
	 *
	 * @param array $items The items.
	 * @param array $config The config.
	 * @param array $context The context.
	 *
	 * @return array The items.
	 */
	public function execute(array $items, array $config, array $context): array {
		return $items;
	}
}//end class

/**
 * The palette's icons.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRegistry
 */
class FlowNodePaletteIconsTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @var string
	 */
	private const APP_ROOT = __DIR__ . '/../../../..';

	/**
	 * Every `imagePath(app, path)` a flow node's getIcon() names.
	 *
	 * The nodes are read from source rather than instantiated because building
	 * all 27 needs the server container; what is asserted — the image a node
	 * asks the palette for — is a literal in every one of them.
	 *
	 * @return array<string, array{0: string, 1: string}> Class file => [app, path].
	 */
	private function declaredIcons(): array {
		$icons = [];
		$found = [];
		$walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::APP_ROOT . '/lib'));
		foreach ($walker as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (str_contains($source, 'implements IFlowNode') === false) {
				continue;
			}

			$found[] = $file->getFilename();
			$matched = preg_match(
				'/function getIcon\(\)[^{]*\{.*?imagePath\(\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*\)/s',
				$source,
				$parts
			);

			$this->assertSame(
				1,
				$matched,
				$file->getFilename() . ' implements IFlowNode but its getIcon() does not name an image this sweep can read; '
				. 'a node this sweep cannot read is a node whose icon nobody checks.'
			);

			$icons[$file->getFilename()] = [$parts[1], $parts[2]];
		}

		$this->assertGreaterThan(20, count($found), 'the node sweep found almost nothing, so it swept nothing');

		return $icons;
	}//end declaredIcons()

	/**
	 * The Nextcloud source root, when the tests can see one.
	 *
	 * Present in CI, where the app is checked out into `server/apps/openregister`;
	 * absent in a standalone clone, which is why the fixture below exists.
	 *
	 * @return string|null The root, or null.
	 */
	private function nextcloudRoot(): ?string {
		$explicit = getenv('OPENREGISTER_TEST_NC_ROOT');
		if (is_string($explicit) === true && $explicit !== '' && is_dir($explicit . '/core/img') === true) {
			return rtrim($explicit, '/');
		}

		$dir = realpath(self::APP_ROOT);
		for ($depth = 0; $depth < 8 && is_string($dir) === true; $depth++) {
			if (is_dir($dir . '/core/img') === true && is_dir($dir . '/apps') === true) {
				return $dir;
			}

			$parent = dirname($dir);
			if ($parent === $dir) {
				break;
			}

			$dir = $parent;
		}

		return null;
	}//end nextcloudRoot()

	/**
	 * The recorded core inventory, for a checkout with no server beside it.
	 *
	 * @return array<string, true> Path => true.
	 */
	private function recordedCoreImages(): array {
		$path = self::APP_ROOT . '/tests/Fixtures/nextcloud-core-images.txt';
		$this->assertFileExists($path, 'the offline core-image inventory is missing, so the sweep has nothing to check against');

		$images = [];
		foreach (file($path, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#') === true) {
				continue;
			}

			$images[$line] = true;
		}

		$this->assertGreaterThan(100, count($images), 'the recorded inventory is too small to be a real one');

		return $images;
	}//end recordedCoreImages()

	/**
	 * EVERY node's icon is an image that exists.
	 *
	 * Resolved against the real server tree when the tests can see one — they
	 * always can in CI — and against the recorded inventory otherwise, so a
	 * standalone clone gets a verdict rather than a skip.
	 *
	 * @return void
	 */
	public function testEveryFlowNodeNamesAnImageThatExists(): void {
		$root = $this->nextcloudRoot();
		$recorded = ($root === null) ? $this->recordedCoreImages() : [];

		$checked = 0;
		foreach ($this->declaredIcons() as $node => [$app, $image]) {
			if ($app === 'openregister') {
				$this->assertFileExists(
					self::APP_ROOT . '/img/' . $image,
					$node . ' names img/' . $image . ", which this app does not ship: the node would be served with the app's icon and its own would never render."
				);
				$checked++;
				continue;
			}

			if ($root !== null) {
				$this->assertFileExists(
					$root . '/' . $app . '/img/' . $image,
					$node . ' names ' . $app . '/img/' . $image . ', which this Nextcloud does not ship: imagePath() throws and the node loses its icon.'
				);
				$checked++;
				continue;
			}

			$this->assertArrayHasKey(
				$image,
				$recorded,
				$node . ' names ' . $app . '/img/' . $image . ', which Nextcloud does not ship (checked against '
				. 'tests/Fixtures/nextcloud-core-images.txt; run with OPENREGISTER_TEST_NC_ROOT set to check a live tree).'
			);
			$checked++;
		}

		$this->assertGreaterThan(20, $checked, 'the sweep verified almost nothing');
	}//end testEveryFlowNodeNamesAnImageThatExists()

	/**
	 * A node whose icon cannot be resolved stays in the palette, and the
	 * failure is an ERROR naming the node.
	 *
	 * This is the half that stops the defect recurring: the inventory above
	 * only knows about the images nodes name TODAY.
	 *
	 * @return void
	 */
	public function testANodeWithAnUnresolvableIconIsReportedNotDropped(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(static function (string $message) use (&$logged): void {
			$logged[] = $message;
		});

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/apps/openregister/img/app-dark.svg');

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (object $event): void {
			if ($event instanceof RegisterFlowNodesEvent === true) {
				$event->registerNode(new PaletteProbeNode(icon: ''));
			}
		});

		$palette = (new FlowNodeRegistry($dispatcher, $logger, $urls))->palette(scope: IManager::SCOPE_ADMIN);

		$entry = null;
		foreach ($palette as $candidate) {
			if ($candidate['id'] === 'test.probe') {
				$entry = $candidate;
			}
		}

		$this->assertNotNull($entry, 'a node with an unresolvable icon vanished from the palette; it cannot be added to a flow at all');
		$this->assertSame('Probe', $entry['displayName']);
		$this->assertSame('/apps/openregister/img/app-dark.svg', $entry['icon'], 'the node should fall back to the app icon');
		$this->assertNotSame([], $logged, 'the icon failure was not reported anywhere');
		$this->assertStringContainsString('test.probe', $logged[0], 'the report does not name the node');
	}//end testANodeWithAnUnresolvableIconIsReportedNotDropped()
}//end class
