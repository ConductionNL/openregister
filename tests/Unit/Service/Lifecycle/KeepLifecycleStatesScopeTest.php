<?php

/**
 * KeepLifecycleStatesScope: the scope ends with its operation, nests, and has one known caller.
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

use OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
 */
class KeepLifecycleStatesScopeTest extends TestCase {

	/**
	 * The files allowed to open the scope, with why.
	 *
	 * A new caller lifts the initial-state check for every create it runs, so it
	 * is added here on purpose or not at all.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_CALLERS = [
		'lib/Controller/RegistersController.php' => 'an administrator\'s historic import, after the administrator check',
	];

	public function testTheScopeEndsWhenTheOperationThrows(): void {
		try {
			KeepLifecycleStatesScope::run(
				static function (): void {
					throw new RuntimeException('import failed');
				}
			);
			$this->fail('The exception should reach the caller.');
		} catch (RuntimeException $e) {
			$this->assertSame('import failed', $e->getMessage());
		}

		$this->assertFalse(KeepLifecycleStatesScope::isActive());
	}//end testTheScopeEndsWhenTheOperationThrows()

	public function testANestedScopeKeepsTheOuterOneOpen(): void {
		$seen = KeepLifecycleStatesScope::run(
			static function (): array {
				$inner = KeepLifecycleStatesScope::run(static fn (): bool => KeepLifecycleStatesScope::isActive());
				return [$inner, KeepLifecycleStatesScope::isActive()];
			}
		);

		$this->assertSame([true, true], $seen);
		$this->assertFalse(KeepLifecycleStatesScope::isActive());
	}//end testANestedScopeKeepsTheOuterOneOpen()

	public function testTheScopeReturnsWhatTheOperationReturns(): void {
		$this->assertSame('summary', KeepLifecycleStatesScope::run(static fn (): string => 'summary'));
		$this->assertFalse(KeepLifecycleStatesScope::isActive());
	}//end testTheScopeReturnsWhatTheOperationReturns()

	public function testOnlyTheKnownCallersOpenTheScope(): void {
		$root = dirname(__DIR__, 4);
		$output = [];
		exec(sprintf('grep -rn "KeepLifecycleStatesScope::run(" --include=*.php %s/lib 2>/dev/null', escapeshellarg($root)), $output);

		$files = [];
		foreach ($output as $line) {
			[$path, , $code] = array_pad(explode(':', $line, 3), 3, '');
			$trimmed = ltrim($code);
			if (str_starts_with($trimmed, '*') === true || str_starts_with($trimmed, '//') === true) {
				continue;
			}

			$files[] = ltrim(str_replace($root, '', $path), '/');
		}

		$files = array_values(array_unique($files));
		sort($files);

		$this->assertSame(array_keys(self::ALLOWED_CALLERS), $files, 'A new caller lifts the initial-state check; add it to ALLOWED_CALLERS with its reason.');
	}//end testOnlyTheKnownCallersOpenTheScope()
}//end class
