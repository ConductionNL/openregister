<?php

/**
 * The K15 interfaces are bound to the class that implements them.
 *
 * Callers outside lib/Service reach these classes only through the interface, so a
 * missing binding is a runtime 500 that no other unit test sees.
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

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Object\CorrectionTargets;
use OCA\OpenRegister\Service\Object\CorrectionTargetsInterface;
use OCA\OpenRegister\Service\Object\DraftRemovalFrame;
use OCA\OpenRegister\Service\Object\DraftRemovalFrameInterface;
use OCA\OpenRegister\Service\Object\RetentionDestruction;
use OCA\OpenRegister\Service\Object\RetentionDestructionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class K15InterfaceBindingsTest extends TestCase {

	/**
	 * Interface => the class it must be bound to.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function bindings(): array {
		return [
			'retention destruction' => [RetentionDestructionInterface::class, RetentionDestruction::class],
			'correction targets' => [CorrectionTargetsInterface::class, CorrectionTargets::class],
			'draft removal frame' => [DraftRemovalFrameInterface::class, DraftRemovalFrame::class],
		];
	}//end bindings()

	#[DataProvider('bindings')]
	public function testTheBindingIsPresent(string $contract, string $service): void {
		$this->assertArrayHasKey($contract, ContractBindings::BINDINGS);
		$this->assertSame($service, ContractBindings::BINDINGS[$contract]);
	}//end testTheBindingIsPresent()

	#[DataProvider('bindings')]
	public function testTheClassImplementsTheInterface(string $contract, string $service): void {
		$this->assertTrue(is_subclass_of($service, $contract));
	}//end testTheClassImplementsTheInterface()
}//end class
