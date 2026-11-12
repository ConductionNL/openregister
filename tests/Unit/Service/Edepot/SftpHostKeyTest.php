<?php

/**
 * The SFTP transport compares a configured host key fingerprint before login.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

class SftpHostKeyTest extends TestCase {

	private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

	protected function setUp(): void {
		// phpseclib is an optional dependency; a bare stand-in is enough to mock it.
		if (class_exists(SFTP::class) === false) {
			eval('namespace phpseclib3\\Net; class SFTP { public function getServerPublicHostKey(): mixed { return false; } }');
		}
	}//end setUp()

	private function assertHostKey(string $pinned): void {
		$sftp = $this->createMock(SFTP::class);
		$sftp->method('getServerPublicHostKey')->willReturn(self::KEY);

		$method = new ReflectionMethod(SftpTransport::class, 'assertHostKey');
		$method->invoke(new SftpTransport(new NullLogger()), $sftp, ['hostKeyFingerprint' => $pinned]);
	}//end assertHostKey()

	public function testAMatchingFingerprintPasses(): void {
		$blob     = base64_decode(explode(' ', self::KEY)[1]);
		$expected = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');

		$this->assertHostKey(pinned: $expected);
		$this->addToAssertionCount(1);
	}//end testAMatchingFingerprintPasses()

	public function testAnotherFingerprintStopsTheTransfer(): void {
		$this->expectException(RuntimeException::class);
		$this->assertHostKey(pinned: 'SHA256:not-this-server');
	}//end testAnotherFingerprintStopsTheTransfer()
}//end class
