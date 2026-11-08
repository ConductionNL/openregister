<?php

/**
 * Golden test for the Office session value object.
 *
 * Pins the session body the browser receives, the read-only flag above all:
 * a session built for a reader must say it may not save, and one built for an
 * editor must not, whatever way the session is constructed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.

use OCA\OpenRegister\Files\Service\File\OfficeSession;
use PHPUnit\Framework\TestCase;

class OfficeSessionGoldenTest extends TestCase {

	/**
	 * Build a session the way OfficeSessionService does.
	 *
	 * @param bool $readOnly Whether the token may not save.
	 */
	private function session(bool $readOnly): OfficeSession {
		return new OfficeSession(
			urlSrc: 'https://office.test/browser/cool.html?',
			wopiSrc: 'https://nc.test/index.php/apps/richdocuments/wopi/files/77_oc',
			token: 'tok',
			tokenTtl: 100000,
			readOnly: $readOnly
		);
	}//end session()

	public function testAnEditorsSessionMaySave(): void {
		$this->assertSame(
			[
				'urlSrc' => 'https://office.test/browser/cool.html?',
				'wopiSrc' => 'https://nc.test/index.php/apps/richdocuments/wopi/files/77_oc',
				'frameAction' => 'https://office.test/browser/cool.html?WOPISrc=https%3A%2F%2Fnc.test%2Findex.php%2Fapps%2Frichdocuments%2Fwopi%2Ffiles%2F77_oc',
				'token' => 'tok',
				'tokenTtl' => 100000,
				'readOnly' => false,
			],
			$this->session(readOnly: false)->toArray()
		);
	}//end testAnEditorsSessionMaySave()

	public function testAReadersSessionMayNotSave(): void {
		$session = $this->session(readOnly: true);

		$this->assertTrue($session->readOnly);
		$this->assertTrue($session->toArray()['readOnly']);
		$this->assertSame('tok', $session->token);
		$this->assertSame(100000, $session->tokenTtl);
	}//end testAReadersSessionMayNotSave()
}//end class
