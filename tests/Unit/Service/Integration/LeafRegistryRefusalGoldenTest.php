<?php

/**
 * Characterisation tests for the LeafRegistry collection path: every refusal
 * message, the render-surface judgement for a disabled or unanswerable app and
 * for a built-in leaf, and the duplicate warning.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafAvailability;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafIdentity;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Service\Integration\LeafRendering;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

/**
 * Pins LeafRegistry collection outcomes before its units are split.
 */
class LeafRegistryRefusalGoldenTest extends TestCase {

	/**
	 * The log lines of the last registry, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $lines = [];

	/**
	 * A registry over the given descriptors and app manager.
	 *
	 * @param array<int, LeafDescriptor> $descriptors The announced leaves.
	 * @param IAppManager|null           $appManager  The app manager.
	 *
	 * @return LeafRegistry
	 */
	private function registry(array $descriptors, ?IAppManager $appManager = null): LeafRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($descriptors): object {
				foreach ($descriptors as $descriptor) {
					$event->registerLeaf($descriptor);
				}

				return $event;
			}
		);

		if ($appManager === null) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isEnabledForUser')->willReturn(true);
		}

		$this->lines = [];
		$lines       = &$this->lines;
		$logger      = new class($lines) extends AbstractLogger {
			public function __construct(private array &$lines) {
			}//end __construct()

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = [(string)$level, (string)$message];
			}//end log()
		};

		return new LeafRegistry(
			eventDispatcher: $dispatcher,
			integrationRegistry: new IntegrationRegistry(new NullLogger()),
			appManager: $appManager,
			logger: $logger
		);
	}//end registry()

	/**
	 * A leaf descriptor.
	 *
	 * @param string             $id         The id.
	 * @param array<int, string> $kinds      The kinds.
	 * @param string             $renderMode The render mode.
	 *
	 * @return LeafDescriptor
	 */
	private function leaf(string $id, array $kinds = [LeafDescriptor::KIND_RENDER_SURFACE], string $renderMode = 'component'): LeafDescriptor {
		return new LeafDescriptor(identity: new LeafIdentity(id: $id, label: 'L', icon: 'Cube'), kinds: $kinds, rendering: new LeafRendering(renderMode: $renderMode));
	}//end leaf()

	/**
	 * Each refusal is logged as a warning with its own message and keeps the leaf out.
	 *
	 * @return void
	 */
	public function testRefusalMessages(): void {
		$registry = $this->registry(
			descriptors: [
				$this->leaf(id: 'Not_Kebab'),
				$this->leaf(id: 'no-kinds', kinds: []),
				$this->leaf(id: 'odd-kind', kinds: ['render-surface', 'weird', 'odd']),
				$this->leaf(id: 'odd-mode', renderMode: 'iframe'),
				$this->leaf(id: 'no-provider', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]),
				$this->leaf(id: 'kept'),
				$this->leaf(id: 'kept'),
			]
		);

		$this->assertSame(['kept'], array_map(static fn (LeafDescriptor $d) => $d->getId(), $registry->getDescriptors()));
		$this->assertSame(
			[
				['warning', '[LeafRegistry] leaf id "Not_Kebab" is not kebab-case — skipping'],
				['warning', '[LeafRegistry] leaf "no-kinds" declares no kinds — skipping'],
				['warning', '[LeafRegistry] leaf "odd-kind" declares unknown kind(s) "weird, odd" — skipping'],
				['warning', '[LeafRegistry] leaf "odd-mode" declares unknown renderMode "iframe" — skipping'],
				['warning', '[LeafRegistry] leaf "no-provider" declares the data-provider kind but supplied no provider — skipping'],
				['warning', '[LeafRegistry] duplicate leaf id "kept" — keeping first registration'],
			],
			$this->lines
		);
	}//end testRefusalMessages()

	/**
	 * A render surface of a disabled app, or of an app whose state cannot be read,
	 * is registered without judging its bundle.
	 *
	 * @return void
	 */
	public function testDisabledOrUnanswerableAppIsNotJudged(): void {
		foreach ([false, null] as $enabled) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('getAppPath')->willReturn(sys_get_temp_dir());
			if ($enabled === null) {
				$appManager->method('isEnabledForUser')->willThrowException(new RuntimeException('no'));
			} else {
				$appManager->method('isEnabledForUser')->willReturn($enabled);
			}

			$registry = $this->registry(
				descriptors: [
					new LeafDescriptor(
						identity: new LeafIdentity(id: 'acme-tab', label: 'Tab', icon: 'Cube'),
						kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
						availability: new LeafAvailability(requiredApp: 'acme'),
						rendering: new LeafRendering(loadStrategy: LeafDescriptor::LOADS_VIA_SHARED_ENTRY),
					),
				],
				appManager: $appManager
			);

			$this->assertCount(1, $registry->getDescriptors());
			$this->assertSame([], $this->lines);
		}//end foreach
	}//end testDisabledOrUnanswerableAppIsNotJudged()

	/**
	 * A render surface whose app has no path, or no required app, is not judged.
	 *
	 * @return void
	 */
	public function testAppWithoutPathIsNotJudged(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new RuntimeException('not installed'));
		$appManager->expects($this->never())->method('isEnabledForUser');

		$registry = $this->registry(
			descriptors: [
				new LeafDescriptor(
					identity: new LeafIdentity(id: 'acme-tab', label: 'Tab', icon: 'Cube'),
					kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
					availability: new LeafAvailability(requiredApp: 'acme'),
					rendering: new LeafRendering(loadStrategy: LeafDescriptor::LOADS_VIA_SHARED_ENTRY),
				),
				new LeafDescriptor(identity: new LeafIdentity(id: 'blank-app', label: 'B', icon: 'Cube'), kinds: [LeafDescriptor::KIND_RENDER_SURFACE], availability: new LeafAvailability(requiredApp: '')),
			],
			appManager: $appManager
		);

		$this->assertCount(2, $registry->getDescriptors());
		$this->assertSame([], $this->lines);
	}//end testAppWithoutPathIsNotJudged()

	/**
	 * The two judgements of a missing bundle log an error each: a claim is refused,
	 * silence is kept.
	 *
	 * @return void
	 */
	public function testMissingBundleJudgementsLogErrors(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(sys_get_temp_dir());
		$appManager->method('isEnabledForUser')->willReturn(true);

		$registry = $this->registry(
			descriptors: [
				new LeafDescriptor(
					identity: new LeafIdentity(id: 'claims', label: 'C', icon: 'Cube'),
					kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
					availability: new LeafAvailability(requiredApp: 'acme'),
					rendering: new LeafRendering(loadStrategy: LeafDescriptor::LOADS_VIA_SHARED_ENTRY),
				),
				new LeafDescriptor(identity: new LeafIdentity(id: 'silent', label: 'S', icon: 'Cube'), kinds: [LeafDescriptor::KIND_RENDER_SURFACE], availability: new LeafAvailability(requiredApp: 'acme')),
			],
			appManager: $appManager
		);

		$this->assertSame(['silent'], array_map(static fn (LeafDescriptor $d) => $d->getId(), $registry->getDescriptors()));
		$this->assertSame(['error', 'error'], array_column($this->lines, 0));
		$this->assertStringContainsString('leaf "claims" declares it loads through the shared', $this->lines[0][1]);
		$this->assertStringContainsString('Refused.', $this->lines[0][1]);
		$this->assertStringContainsString('leaf "silent" declares a render surface but app "acme"', $this->lines[1][1]);
	}//end testMissingBundleJudgementsLogErrors()
}//end class
