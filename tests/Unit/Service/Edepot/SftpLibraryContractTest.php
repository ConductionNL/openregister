<?php

/**
 * The phpseclib4 calls SftpTransport makes exist on the real library, which the
 * stub of SftpTransportGoldenTest cannot show.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
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

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use PHPUnit\Framework\TestCase;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Net\SFTP;

/**
 * @coversNothing
 */
class SftpLibraryContractTest extends TestCase {

	/**
	 * Every method the transport calls is a method of the installed library.
	 *
	 * @return void
	 */
	public function testTheTransportCallsExist(): void {
		if (class_exists(SFTP::class) === false) {
			$this->markTestSkipped('phpseclib4 is not in this vendor directory.');
		}

		foreach (['getServerPublicHostKey', 'login', 'put', 'filesize', 'pwd'] as $method) {
			$this->assertTrue(method_exists(SFTP::class, $method), $method);
		}

		$this->assertTrue(defined(SFTP::class . '::SOURCE_LOCAL_FILE'));
		$this->assertTrue(method_exists(PublicKeyLoader::class, 'load'));
	}//end testTheTransportCallsExist()
}//end class
