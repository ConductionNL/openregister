<?php

/**
 * DecisionTableLocation holds the slugs DecisionTableStore reads tables from.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dmn
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;

use OCA\OpenRegister\Service\Dmn\DecisionTableLocation;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Dmn\DecisionTableLocation
 */
final class DecisionTableLocationTest extends TestCase {

	/**
	 * The slugs are unchanged, and the store reads the same ones.
	 *
	 * @return void
	 */
	public function testTheStoreReadsTheLocationSlugs(): void {
		$this->assertSame('decision-tables', DecisionTableLocation::REGISTER);
		$this->assertSame('decision-table', DecisionTableLocation::SCHEMA);
		$this->assertSame(DecisionTableLocation::REGISTER, DecisionTableStore::REGISTER);
		$this->assertSame(DecisionTableLocation::SCHEMA, DecisionTableStore::SCHEMA);
	}//end testTheStoreReadsTheLocationSlugs()

	/**
	 * The decision-table validation listener reads the slugs without naming the store.
	 *
	 * @return void
	 */
	public function testTheListenerDoesNotNameTheStore(): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../../lib/Listener/DecisionTableValidationListener.php');
		$this->assertStringNotContainsString('DecisionTableStore', $source);
	}//end testTheListenerDoesNotNameTheStore()
}//end class
