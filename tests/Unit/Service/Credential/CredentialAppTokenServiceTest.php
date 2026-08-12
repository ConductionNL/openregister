<?php

/**
 * CredentialAppTokenServiceTest — issue/verify round-trip + forged/expired rejects.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialAppTokenService
 */
class CredentialAppTokenServiceTest extends TestCase {
	/** @var array<string, mixed> In-memory vault keyed by identifier. */
	private array $stored = [];

	private int $now = 1000;

	private CredentialAppTokenService $service;

	protected function setUp(): void {
		$this->stored = [];
		$this->now = 1000;

		$vault = $this->createMock(ICredentialsManager::class);
		$vault->method('store')->willReturnCallback(
			function (string $userId, string $identifier, $credentials): void {
				$this->stored[$identifier] = $credentials;
			}
		);
		$vault->method('retrieve')->willReturnCallback(
			fn (string $userId, string $identifier) => ($this->stored[$identifier] ?? null)
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('app-signing-secret');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		$this->service = new CredentialAppTokenService($vault, $random, $time);
	}

	public function testIssueVerifyRoundTrip(): void {
		$this->service->registerApp('hermiq');
		$token = $this->service->issueToken('hermiq', 'cred-1');
		$claims = $this->service->verify($token);

		$this->assertSame('hermiq', $claims['appId']);
		$this->assertSame('cred-1', $claims['credentialId']);
	}

	public function testForgedSignatureRejected(): void {
		$this->service->registerApp('hermiq');
		$token = $this->service->issueToken('hermiq', 'cred-1');

		// Tamper with the signature segment.
		[$payload, $sig] = explode('.', $token);
		$forged = $payload . '.' . strrev($sig) . 'x';

		$this->expectException(CredentialAccessDeniedException::class);
		$this->service->verify($forged);
	}

	public function testExpiredTokenRejected(): void {
		$this->service->registerApp('hermiq');
		$token = $this->service->issueToken('hermiq', 'cred-1');

		// Advance the clock past the token TTL (300s).
		$this->now = 9999;

		$this->expectException(CredentialAccessDeniedException::class);
		$this->service->verify($token);
	}

	public function testUnregisteredAppCannotIssue(): void {
		$this->expectException(CredentialAccessDeniedException::class);
		$this->service->issueToken('never-registered', 'cred-1');
	}
}//end class
