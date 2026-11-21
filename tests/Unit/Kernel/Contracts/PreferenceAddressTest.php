<?php

/**
 * A preference address falls back to the global scope without changing the original address.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\PreferenceAddress;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Kernel\Contracts\PreferenceAddress
 */
class PreferenceAddressTest extends TestCase {

	public function testAnUnscopedAddressBecomesGlobal(): void {
		$address = new PreferenceAddress(schemaSlug: 'permit', notificationKey: 'status-changed');

		$global = $address->scopedOrGlobal();

		$this->assertNotSame($address, $global);
		$this->assertSame('global', $global->scope);
		$this->assertSame('permit', $global->schemaSlug);
		$this->assertSame('status-changed', $global->notificationKey);
		$this->assertNull($address->scope);
	}//end testAnUnscopedAddressBecomesGlobal()

	public function testAScopedAddressKeepsItsScope(): void {
		$address = new PreferenceAddress(schemaSlug: 'permit', notificationKey: 'assigned', scope: 'object:42');

		$scoped = $address->scopedOrGlobal();

		$this->assertSame('object:42', $scoped->scope);
		$this->assertSame('permit', $scoped->schemaSlug);
		$this->assertSame('assigned', $scoped->notificationKey);
	}//end testAScopedAddressKeepsItsScope()

	public function testAnEmptyScopeIsKeptAndNotTreatedAsMissing(): void {
		$address = new PreferenceAddress(schemaSlug: 'permit', notificationKey: 'assigned', scope: '');

		$this->assertSame('', $address->scopedOrGlobal()->scope);
	}//end testAnEmptyScopeIsKeptAndNotTreatedAsMissing()
}//end class
