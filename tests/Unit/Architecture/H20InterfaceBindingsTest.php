<?php

/**
 * The interfaces lane H20 added, and the boundaries they keep.
 *
 * BulkActionOutcomeInterface is the outcome a bulk action returns, as the bulk
 * job executor reads it. It is a value type and is never injected, so it has
 * no container binding: what this pins is that both outcome classes implement
 * it and that no lib file outside lib/BulkAction names BulkActionResult.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\BulkAction\BulkActionOutcomeInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Service\BulkJob\BulkJobRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class H20InterfaceBindingsTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function implementations(): array {
		return [
			'BulkActionResult' => [BulkActionResult::class, BulkActionOutcomeInterface::class],
			'BulkJobRefusal' => [BulkJobRefusal::class, BulkActionOutcomeInterface::class],
		];
	}//end implementations()

	#[DataProvider('implementations')]
	public function testTheClassImplementsTheInterface(string $class, string $interface): void {
		$this->assertContains($interface, class_implements($class), $class . ' implements ' . $interface);
	}//end testTheClassImplementsTheInterface()

	public function testTheExecutorsRefusalReadsAsTheActionsOwnRefusal(): void {
		$own = BulkActionResult::refused('the update rule does not include bob');
		$executor = new BulkJobRefusal('the update rule does not include bob');

		$this->assertSame(BulkJobMember::OUTCOME_REFUSED, $executor->getOutcome());
		$this->assertSame($own->getOutcome(), $executor->getOutcome());
		$this->assertSame($own->getReason(), $executor->getReason());
		$this->assertSame($own->isApplied(), $executor->isApplied());
	}//end testTheExecutorsRefusalReadsAsTheActionsOwnRefusal()

	public function testNoFileOutsideBulkActionNamesBulkActionResult(): void {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = str_replace('\\', '/', $file->getPathname());
			if ($file->isFile() === false || str_ends_with($path, '.php') === false
				|| str_contains($path, '/lib/BulkAction/') === true
			) {
				continue;
			}

			$code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
			if (preg_match('/\bBulkActionResult\b/', (string) $code) === 1) {
				$found[] = substr($path, (strpos($path, '/lib/') + 5));
			}
		}

		$this->assertSame([], $found, 'Type against BulkActionOutcomeInterface instead.');
	}//end testNoFileOutsideBulkActionNamesBulkActionResult()
}//end class
