<?php

/**
 * LifecycleStatesKeeper: the Core port's answer opens the lifecycle's own scope
 * for exactly the operation, and only the register import asks for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Kernel\Contracts\Core\LifecycleStatesKeeperInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope;
use OCA\OpenRegister\Service\Lifecycle\LifecycleStatesKeeper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleStatesKeeper
 * @uses   \OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
 */
final class LifecycleStatesKeeperTest extends TestCase {

	public function testTheOperationRunsInsideTheScopeAndItsResultIsReturned(): void {
		$seen = (new LifecycleStatesKeeper())->keepingStates(static fn (): array => ['active' => KeepLifecycleStatesScope::isActive()]);

		self::assertSame(['active' => true], $seen);
		self::assertFalse(KeepLifecycleStatesScope::isActive());
	}

	public function testTheScopeEndsWhenTheOperationThrows(): void {
		try {
			(new LifecycleStatesKeeper())->keepingStates(static function (): never {
				throw new RuntimeException('import failed');
			});
			self::fail('the exception reaches the caller');
		} catch (RuntimeException $e) {
			self::assertSame('import failed', $e->getMessage());
		}

		self::assertFalse(KeepLifecycleStatesScope::isActive());
	}

	public function testTheProcessesWiringBindsThePort(): void {
		self::assertSame(LifecycleStatesKeeper::class, FlowWiring::BINDINGS[LifecycleStatesKeeperInterface::class] ?? null);
		self::assertTrue(is_subclass_of(LifecycleStatesKeeper::class, LifecycleStatesKeeperInterface::class));
	}

	public function testOnlyTheRegisterImportAsksForThePort(): void {
		$root = dirname(__DIR__, 4);
		$output = [];
		exec(sprintf('grep -rln "LifecycleStatesKeeperInterface::class)" --include=*.php %s/lib 2>/dev/null', escapeshellarg($root)), $output);

		$files = array_map(static fn (string $path): string => ltrim(str_replace($root, '', $path), '/'), $output);
		sort($files);

		self::assertSame(['lib/Controller/RegistersController.php'], $files, 'A new caller lifts the initial-state check; it needs its own reason.');
	}
}
