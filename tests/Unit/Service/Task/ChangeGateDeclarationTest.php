<?php

/**
 * Reading a declared change gate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Service\Task\ChangeGateDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Task\ChangeGateDeclaration
 */
class ChangeGateDeclarationTest extends TestCase {

	public function testAChainWithoutAChangeGateDeclaresNone(): void {
		foreach ([[], ['transition' => 'approve'], ['gate' => 'change'], ['gate' => ['transition' => 'x']]] as $chain) {
			self::assertFalse(ChangeGateDeclaration::declaredIn(chain: $chain), json_encode($chain));
			self::assertNull(ChangeGateDeclaration::fromChain(chain: $chain), json_encode($chain));
		}
	}//end testAChainWithoutAChangeGateDeclaresNone()

	public function testAFullDeclarationIsTrimmedDeduplicatedAndOrdered(): void {
		$gate = ChangeGateDeclaration::fromChain(
			chain: ['gate' => ['change' => ['properties' => [' iban ', 'iban', 'bic'], 'on' => ['update', 'create'], 'exempt' => ['admin']]]]
		);

		self::assertSame(
			['properties' => ['iban', 'bic'], 'on' => ['create', 'update'], 'exempt' => ['admin']],
			$gate->toArray()
		);
	}//end testAFullDeclarationIsTrimmedDeduplicatedAndOrdered()

	public function testAnEmptyDeclarationGatesAnyChangeOnUpdateOnly(): void {
		$gate = ChangeGateDeclaration::fromChain(chain: ['gate' => ['change' => []]]);

		self::assertSame(['properties' => [], 'on' => ['update'], 'exempt' => []], $gate->toArray());
		self::assertTrue($gate->gates(operation: 'update'));
		self::assertFalse($gate->gates(operation: 'create'));
		self::assertTrue($gate->coversProperty(property: 'anything'));
		self::assertFalse($gate->isExempt(groups: ['admin']));
	}//end testAnEmptyDeclarationGatesAnyChangeOnUpdateOnly()

	public function testListedPropertiesAndExemptGroupsAreMatchedExactly(): void {
		$gate = ChangeGateDeclaration::fromChain(
			chain: ['gate' => ['change' => ['properties' => ['iban'], 'on' => ['create'], 'exempt' => ['sync', 'migratie']]]]
		);

		self::assertTrue($gate->coversProperty(property: 'iban'));
		self::assertFalse($gate->coversProperty(property: 'phone'));
		self::assertFalse($gate->coversProperty(property: 'IBAN'));
		self::assertTrue($gate->gates(operation: 'create'));
		self::assertFalse($gate->gates(operation: 'update'));
		self::assertTrue($gate->isExempt(groups: ['staff', 'migratie']));
		self::assertFalse($gate->isExempt(groups: ['staff']));
		self::assertFalse($gate->isExempt(groups: []));
	}//end testListedPropertiesAndExemptGroupsAreMatchedExactly()

	/**
	 * A declared but unreadable gate is malformed, never silently narrowed.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationIsNull(): void {
		$malformed = [
			'change not a map' => 'iban',
			'properties not a list' => ['properties' => 'iban'],
			'properties a map' => ['properties' => ['a' => 'iban']],
			'empty property' => ['properties' => ['iban', ' ']],
			'non-string property' => ['properties' => [7]],
			'unknown operation' => ['on' => ['update', 'delete']],
			'no operation' => ['on' => []],
			'exempt not a list' => ['exempt' => 'admin'],
			'non-string exempt' => ['exempt' => [null]],
		];

		foreach ($malformed as $case => $change) {
			$chain = ['gate' => ['change' => $change]];
			self::assertTrue(ChangeGateDeclaration::declaredIn(chain: $chain), $case);
			self::assertNull(ChangeGateDeclaration::fromChain(chain: $chain), $case);
		}
	}//end testAMalformedDeclarationIsNull()
}//end class
