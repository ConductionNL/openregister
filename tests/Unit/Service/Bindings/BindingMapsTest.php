<?php

/**
 * Every area binding map is spread into ContractBindings::BINDINGS.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Bindings;

use OCA\OpenRegister\Service\Bindings\AccessBindings;
use OCA\OpenRegister\Service\Bindings\FeatureBindings;
use OCA\OpenRegister\Service\ContractBindings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class BindingMapsTest extends TestCase {

	/**
	 * The area maps ContractBindings spreads.
	 *
	 * @var list<class-string>
	 */
	private const MAPS = [
		AccessBindings::class,
		FeatureBindings::class,
	];

	/**
	 * One case per map.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function maps(): array {
		$cases = [];
		foreach (self::MAPS as $map) {
			$cases[$map] = [$map];
		}

		return $cases;
	}//end maps()

	/**
	 * ContractBindings spreads the map, so each of its entries is bound.
	 *
	 * @param class-string $map The area map.
	 *
	 * @return void
	 */
	#[DataProvider('maps')]
	public function testTheMapIsSpreadIntoContractBindings(string $map): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../../lib/Service/ContractBindings.php');
		$short  = substr($map, (int) strrpos($map, '\\') + 1);
		$this->assertStringContainsString('...' . $short . '::BINDINGS,', $source);
		$this->assertStringContainsString('use ' . $map . ';', $source);
		foreach (constant($map . '::BINDINGS') as $contract => $service) {
			$this->assertSame($service, ContractBindings::BINDINGS[$contract] ?? null, $contract . ' is bound');
		}
	}//end testTheMapIsSpreadIntoContractBindings()
}//end class
