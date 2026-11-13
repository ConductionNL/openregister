<?php

/**
 * Golden test pinning CredentialAppTokenService's issued token format and every
 * allowed and refused verify outcome.
 *
 * Written against the code before its helpers were narrowed (ADR-011), so the
 * restructure has to reproduce every token byte and every refusal message.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Credential\BrokerTokenBinding;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialAppTokenService
 * @uses \OCA\OpenRegister\Service\Credential\BrokerTokenBinding
 */
class CredentialAppTokenOutcomeGoldenTest extends TestCase {
	/**
	 * The clock every token is issued and verified at, in seconds.
	 *
	 * @var integer
	 */
	private int $now = 1000;

	/**
	 * A service whose vault holds one secret for `pipelinq`, over a settable clock.
	 *
	 * @return CredentialAppTokenService
	 */
	private function service(): CredentialAppTokenService {
		$vault = $this->createMock(ICredentialsManager::class);
		$vault->method('retrieve')->willReturnCallback(
			static fn (string $user, string $key): ?string => ($key === 'openregister/credential-app-key/pipelinq' ? 'S3CRET' : null)
		);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new CredentialAppTokenService($vault, $this->createMock(ISecureRandom::class), $clock);
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw The bytes.
	 *
	 * @return string
	 */
	private static function b64(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	/**
	 * A token signed with the given secret over the given claims.
	 *
	 * @param array<string, mixed> $claims The claims.
	 * @param string               $secret The signing secret.
	 *
	 * @return string
	 */
	private static function forge(array $claims, string $secret='S3CRET'): string {
		$payload = self::b64((string)json_encode($claims));
		return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $secret, true));
	}

	/**
	 * The issued tokens are byte-for-byte the documented format.
	 *
	 * @return void
	 */
	public function testIssuedTokenFormat(): void {
		$service = $this->service();

		$this->assertSame(
			self::forge(['appId' => 'pipelinq', 'credentialId' => 'c-1', 'iat' => 1000, 'exp' => 1300]),
			$service->issueToken('pipelinq', 'c-1')
		);
		$this->assertSame(
			self::forge(['appId' => 'pipelinq', 'credentialId' => 'c-1', 'iat' => 1000, 'exp' => 1300, 'req' => hash('sha256', 'GET /v1/x')]),
			$service->issueToken('pipelinq', 'c-1', new BrokerTokenBinding('get', 'v1/x'))
		);
		$this->assertSame(
			self::forge(['appId' => 'pipelinq', 'credentialId' => 'c-1', 'iat' => 1000, 'exp' => 1300]),
			$service->issueToken('pipelinq', 'c-1', new BrokerTokenBinding('GET', null)),
			'A method without a path leaves the token unbound.'
		);
	}

	/**
	 * An app without a secret cannot be issued a token.
	 *
	 * @return void
	 */
	public function testIssueRefusesAnUnregisteredApp(): void {
		$this->expectException(CredentialAccessDeniedException::class);
		$this->expectExceptionMessage('App "ghost" has no registered signing secret');
		$this->service()->issueToken('ghost', 'c-1');
	}

	/**
	 * Every verify outcome: the claims returned, or the refusal message.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: array<string, string>|string}>
	 */
	public static function verifyCases(): array {
		$unbound = ['appId' => 'pipelinq', 'credentialId' => 'c-1', 'iat' => 1000, 'exp' => 1300];
		$bound   = $unbound + ['req' => hash('sha256', 'PUT /v1/items/1')];
		$ok      = ['appId' => 'pipelinq', 'credentialId' => 'c-1'];
		return [
			'unbound, no call named'        => [self::forge($unbound), null, null, $ok],
			'unbound, any call'             => [self::forge($unbound), 'DELETE', '/x', $ok],
			'bound, same call'              => [self::forge($bound), 'put', 'v1/items/1', $ok],
			'bound, other path'             => [self::forge($bound), 'PUT', '/v1/items/2', 'Broker token request-binding mismatch'],
			'bound, other method'           => [self::forge($bound), 'GET', '/v1/items/1', 'Broker token request-binding mismatch'],
			'bound, no method'              => [self::forge($bound), null, '/v1/items/1', 'Broker token request-binding mismatch'],
			'bound, no path'                => [self::forge($bound), 'PUT', null, 'Broker token request-binding mismatch'],
			'expired'                       => [self::forge(['exp' => 1000] + $unbound), null, null, 'Expired broker token'],
			'wrong secret'                  => [self::forge($unbound, 'OTHER'), null, null, 'Bad broker token signature'],
			'unknown app'                   => [self::forge(['appId' => 'ghost'] + $unbound), null, null, 'Unknown app "ghost" in broker token'],
			'one segment'                   => ['abc', null, null, 'Malformed broker token'],
			'empty signature'               => ['abc.', null, null, 'Malformed broker token'],
			'three segments'                => ['a.b.c', null, null, 'Malformed broker token'],
			'payload not base64'            => ['!!!.sig', null, null, 'Malformed broker token payload'],
			'payload not json'              => [self::b64('nope') . '.sig', null, null, 'Malformed broker token claims'],
			'exp not an integer'            => [self::forge(['exp' => '1300'] + $unbound), null, null, 'Malformed broker token claims'],
			'credentialId not a string'     => [self::forge(['credentialId' => 7] + $unbound), null, null, 'Malformed broker token claims'],
			'expired and bound, wrong call' => [self::forge(['exp' => 999] + $bound), 'GET', '/', 'Expired broker token'],
			'bad signature beats expiry'    => [self::forge(['exp' => 999] + $unbound, 'OTHER'), null, null, 'Bad broker token signature'],
		];
	}

	/**
	 * Pin verify's allowed claims and each refusal.
	 *
	 * @param string                       $token    The presented token.
	 * @param string|null                  $method   The actual method.
	 * @param string|null                  $path     The actual path.
	 * @param array<string, string>|string $expected The claims, or the refusal message.
	 *
	 * @return void
	 */
	#[DataProvider('verifyCases')]
	public function testVerifyOutcome(string $token, ?string $method, ?string $path, array|string $expected): void {
		try {
			$claims = $this->service()->verify($token, new BrokerTokenBinding($method, $path));
		} catch (CredentialAccessDeniedException $refused) {
			$this->assertSame($expected, $refused->getMessage());
			return;
		}

		$this->assertSame($expected, $claims);
	}

	/**
	 * A token issued now round-trips, and stops verifying once the clock passes exp.
	 *
	 * @return void
	 */
	public function testIssuedTokenExpiresAtTheTtl(): void {
		$service = $this->service();
		$token   = $service->issueToken('pipelinq', 'c-9', new BrokerTokenBinding('POST', '/send'));

		$this->now = 1299;
		$this->assertSame(['appId' => 'pipelinq', 'credentialId' => 'c-9'], $service->verify($token, new BrokerTokenBinding('POST', 'send')));

		$this->now = 1300;
		$this->expectExceptionMessage('Expired broker token');
		$service->verify($token, new BrokerTokenBinding('POST', 'send'));
	}
}
