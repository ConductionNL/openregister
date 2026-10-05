<?php

/**
 * OAuth2StateServiceTest — the value the whole connect flow's safety rests on.
 *
 * A `state` is the only thing that travels with the user through the provider and
 * back, so the four ways it can be abused are the four tests here: forged, expired,
 * replayed, or used to get at the PKCE verifier. The verifier test is the one that
 * makes the relay safe to be dumb, because a code forwarded to a host that has no
 * verifier is worth nothing.
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
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\OAuth2StateService;
use OCP\Security\ICredentialsManager;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2StateService
 */
class OAuth2StateServiceTest extends TestCase {
	/** @var string Separates the owner from the identifier in a fake vault key. */
	private const OWNER_SEPARATOR = '|';

	/** @var array<string, string> The fake encrypted vault, keyed by owner, separator and identifier. */
	private array $vault = [];

	protected function setUp(): void {
		$this->vault = [];
	}

	public function testAnIssuedStateRedeemsOnceAndReturnsItsClaims(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'p' => 'mastodon', 's' => 'personal']);

		$redeemed = $service->consume(state: $issued['state']);

		$this->assertNotNull($redeemed);
		$this->assertSame('alice', $redeemed['claims']['u']);
		$this->assertSame('mastodon', $redeemed['claims']['p']);
		$this->assertSame($issued['verifier'], $redeemed['verifier']);
	}

	public function testAReplayedStateIsRefused(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'p' => 'mastodon']);

		$this->assertNotNull($service->consume(state: $issued['state']), 'the first presentation is honoured');
		$this->assertNull($service->consume(state: $issued['state']), 'the second must be refused');
	}

	public function testATamperedStateIsRefused(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'p' => 'mastodon']);

		// Re-encode the payload with a different user, keeping the original signature.
		[$payload, $signature] = explode('.', $issued['state']);
		$claims = json_decode($this->base64UrlDecode($payload), true);
		$claims['u'] = 'mallory';
		$forged = $this->base64UrlEncode((string)json_encode($claims)) . '.' . $signature;

		$this->assertNull($service->consume(state: $forged));
	}

	public function testAStateWithADifferentSignatureIsRefused(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'p' => 'mastodon']);
		[$payload] = explode('.', $issued['state']);

		$this->assertNull($service->consume(state: $payload . '.' . $this->base64UrlEncode('not-the-signature')));
	}

	public function testAnExpiredStateIsRefused(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'p' => 'mastodon']);

		// Re-sign the payload with an expiry in the past. A correctly signed but
		// stale state is the case a signature check alone would wave through.
		[$payload] = explode('.', $issued['state']);
		$claims = json_decode($this->base64UrlDecode($payload), true);
		$claims['exp'] = (time() - 1);
		$stale = $this->base64UrlEncode((string)json_encode($claims));

		$this->assertNull($service->consume(state: $stale . '.' . $this->base64UrlEncode('hmac:' . $stale)));
	}

	public function testAMalformedStateIsRefused(): void {
		$service = $this->makeService();

		$this->assertNull($service->consume(state: ''));
		$this->assertNull($service->consume(state: 'no-dot-here'));
		$this->assertNull($service->consume(state: 'a.b.c'));
	}

	public function testTheVerifierIsNeverPartOfTheState(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'p' => 'mastodon']);

		$this->assertStringNotContainsString($issued['verifier'], $issued['state']);
		$this->assertStringNotContainsString($issued['verifier'], $issued['challenge']);
	}

	public function testTheChallengeIsTheS256OfTheVerifier(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice']);

		$expected = rtrim(strtr(base64_encode(hash('sha256', $issued['verifier'], true)), '+/', '-_'), '=');
		$this->assertSame($expected, $issued['challenge']);
	}

	public function testTheRelayCanReadADestinationWithoutVerifying(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'cb' => 'https://tenant.example/apps/openregister/oauth2/callback']);

		$shape = $service->parseUnverified(state: $issued['state']);

		$this->assertSame('https://tenant.example/apps/openregister/oauth2/callback', $shape['cb']);
	}

	public function testAStateWhosePendingRecordIsGoneIsRefused(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['u' => 'alice', 'p' => 'mastodon']);

		// The vault losing the record is what a completed or swept flow looks like.
		$this->vault = [];

		$this->assertNull($service->consume(state: $issued['state']));
	}

	/**
	 * Base64url-encode without padding.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The encoded value.
	 */
	private function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	/**
	 * Base64url-decode.
	 *
	 * @param string $value The encoded value.
	 *
	 * @return string The raw value.
	 */
	private function base64UrlDecode(string $value): string {
		return (string)base64_decode(strtr($value, '-_', '+/'), true);
	}

	/**
	 * The pending record's key fits Nextcloud's credential vault, whose
	 * `oc_storages_credentials.identifier` column holds 64 characters. A longer
	 * key fails the insert, and with it every connect start, wherever the length
	 * is enforced (PostgreSQL, MySQL in strict mode).
	 *
	 * @return void
	 */
	public function testThePendingRecordKeyFitsTheVaultIdentifierColumn(): void {
		$this->makeService()->issue(claims: ['sub' => 'user-1']);

		self::assertNotEmpty($this->vault);
		foreach (array_keys($this->vault) as $key) {
			$identifier = substr($key, (strpos($key, self::OWNER_SEPARATOR) + 1));
			self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
		}
	}

	/**
	 * A withdrawn flow leaves nothing in the vault, and its state no longer redeems.
	 *
	 * @return void
	 */
	public function testAWithdrawnStateLeavesNothingBehindAndCannotBeRedeemed(): void {
		$service = $this->makeService();
		$issued = $service->issue(claims: ['sub' => 'user-1']);

		$service->withdraw(nonce: $issued['nonce']);

		self::assertSame([], $this->vault);
		self::assertNull($service->consume(state: $issued['state']));
	}

	/**
	 * Build the service with a deterministic signer, random source and vault.
	 *
	 * @return OAuth2StateService The service under test.
	 */
	private function makeService(): OAuth2StateService {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(
			static fn (string $message): string => 'hmac:' . $message
		);

		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length) use (&$counter): string {
				$counter++;
				return str_pad('r' . $counter, $length, 'x');
			}
		);

		$vault = $this->createMock(ICredentialsManager::class);
		// Keyed by owner AND identifier, as the real vault is: a call made under
		// the wrong owner then misses the record instead of passing by accident.
		$vault->method('store')->willReturnCallback(
			function (string $user, string $identifier, $value): void {
				$this->vault[$user . self::OWNER_SEPARATOR . $identifier] = (string)$value;
			}
		);
		$vault->method('retrieve')->willReturnCallback(
			fn (string $user, string $identifier) => ($this->vault[$user . self::OWNER_SEPARATOR . $identifier] ?? null)
		);
		$vault->method('delete')->willReturnCallback(
			function (string $user, string $identifier): int {
				$key = $user . self::OWNER_SEPARATOR . $identifier;
				$existed = (int)array_key_exists($key, $this->vault);
				unset($this->vault[$key]);
				return $existed;
			}
		);

		return new OAuth2StateService(crypto: $crypto, vault: $vault, random: $random);
	}
}
