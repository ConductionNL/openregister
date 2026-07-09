<?php

/**
 * CredentialAppTokenBindingTest — request-binding (method+path) on broker tokens.
 *
 * Pins: unbound tokens (no method/path at issue time) keep working exactly as
 * before, including when verified WITH a method/path (binding is opt-in, never
 * retroactively enforced); a token minted WITH a method+path verifies only for
 * that exact method+path and is rejected for any other method, any other path,
 * or when verified without a method/path at all (fail-closed).
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
 *
 * @spec openspec/changes/harden-credential-token-binding/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class CredentialAppTokenBindingTest extends TestCase
{
    private const APP_ID        = 'spectr';

    private const CREDENTIAL_ID = '00000000-0000-0000-0000-000000000000';

    private const SECRET        = 'a-known-per-app-signing-secret-for-tests';

    /**
     * Build a service whose credentials manager resolves `self::APP_ID` to a
     * known secret, and whose clock is fixed so issued tokens never expire
     * mid-test.
     */
    private function makeService(): CredentialAppTokenService
    {
        $credentialsManager = $this->createMock(ICredentialsManager::class);
        $credentialsManager->method('retrieve')->willReturnCallback(
            function (string $userId, string $key) {
                if ($key === 'openregister/credential-app-key/'.self::APP_ID) {
                    return self::SECRET;
                }

                return null;
            }
        );

        $secureRandom = $this->createMock(ISecureRandom::class);

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getTime')->willReturn(1000);

        return new CredentialAppTokenService($credentialsManager, $secureRandom, $timeFactory);
    }

    /**
     * (1) An unbound token (no method/path at issue) round-trips through
     * verify() with no method/path — unchanged, backward-compatible behaviour.
     */
    public function testUnboundTokenRoundTripsWithoutMethodOrPath(): void
    {
        $service = $this->makeService();

        $token  = $service->issueToken(self::APP_ID, self::CREDENTIAL_ID);
        $claims = $service->verify($token);

        $this->assertSame(self::APP_ID, $claims['appId']);
        $this->assertSame(self::CREDENTIAL_ID, $claims['credentialId']);
    }

    /**
     * (2) A token issued WITH a method+path verifies successfully when the
     * SAME method+path are presented to verify().
     */
    public function testBoundTokenVerifiesWithMatchingMethodAndPath(): void
    {
        $service = $this->makeService();

        $token  = $service->issueToken(self::APP_ID, self::CREDENTIAL_ID, 'GET', '/notices');
        $claims = $service->verify($token, 'GET', '/notices');

        $this->assertSame(self::APP_ID, $claims['appId']);
        $this->assertSame(self::CREDENTIAL_ID, $claims['credentialId']);
    }

    /**
     * (3) A bound token verified against a DIFFERENT path is rejected.
     */
    public function testBoundTokenRejectsDifferentPath(): void
    {
        $service = $this->makeService();

        $token = $service->issueToken(self::APP_ID, self::CREDENTIAL_ID, 'GET', '/notices');

        $this->expectException(CredentialAccessDeniedException::class);
        $service->verify($token, 'GET', '/other');
    }

    /**
     * (4) A bound token verified against a DIFFERENT method is rejected.
     */
    public function testBoundTokenRejectsDifferentMethod(): void
    {
        $service = $this->makeService();

        $token = $service->issueToken(self::APP_ID, self::CREDENTIAL_ID, 'GET', '/notices');

        $this->expectException(CredentialAccessDeniedException::class);
        $service->verify($token, 'POST', '/notices');
    }

    /**
     * (5) A bound token verified WITHOUT any method/path is rejected
     * (fail-closed — a captured bound token cannot be replayed unbound).
     */
    public function testBoundTokenRejectsWhenVerifiedWithoutBinding(): void
    {
        $service = $this->makeService();

        $token = $service->issueToken(self::APP_ID, self::CREDENTIAL_ID, 'GET', '/notices');

        $this->expectException(CredentialAccessDeniedException::class);
        $service->verify($token);
    }

    /**
     * (6) An unbound token (no method/path at issue) verified WITH a
     * method/path still succeeds — binding is only enforced when the token
     * actually carries a `req` claim.
     */
    public function testUnboundTokenVerifiesEvenWhenMethodAndPathProvided(): void
    {
        $service = $this->makeService();

        $token  = $service->issueToken(self::APP_ID, self::CREDENTIAL_ID);
        $claims = $service->verify($token, 'GET', '/notices');

        $this->assertSame(self::APP_ID, $claims['appId']);
        $this->assertSame(self::CREDENTIAL_ID, $claims['credentialId']);
    }
}
