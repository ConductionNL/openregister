<?php

/**
 * OAuth2TokenSetGoldenTest — pins token-set decoding and the account-identity read.
 *
 * The edge cases a restructuring of {@see \OCA\OpenRegister\Service\Credential\OAuth2TokenSet}
 * and {@see \OCA\OpenRegister\Service\Credential\OAuth2AccountIdentity} could quietly
 * move: which fields survive a round trip, which fallbacks apply, which refusals name
 * what, and which identity answers are recorded. Every clock is a fixed value.
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

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\OAuth2AccountIdentity;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of token-set decoding and identity recording.
 */
class OAuth2TokenSetGoldenTest extends TestCase {
	/** @var array<int, array{0: string, 1: string, 2: OAuth2TokenSet}> Persisted sets. */
	private array $persisted = [];

	public function testATokenResponseIsReadWithEveryFallback(): void {
		$now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
		$previous = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'OLD', 'refresh_token' => 'OLDRT', 'scope' => 'a b'],
			now: $now
		)->withAccount(id: '1', handle: 'h', displayName: 'd');

		$set = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => ' NEW ', 'expires_in' => '60', 'token_type' => ' ', 'scope' => ''],
			requestedScopes: [],
			previous: $previous,
			now: $now
		);

		$this->assertSame(
			[
				'accessToken' => 'NEW', 'refreshToken' => 'OLDRT', 'tokenType' => 'Bearer', 'expiresAt' => '2026-01-01T00:01:00+00:00',
				'scopes' => ['a', 'b'], 'account' => ['id' => '1', 'handle' => 'h', 'displayName' => 'd'],
				'raw' => ['access_token' => ' NEW ', 'expires_in' => '60', 'token_type' => ' ', 'scope' => ''],
				'obtainedAt' => '2026-01-01T00:00:00+00:00',
			],
			json_decode($set->toStoredJson(), true)
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>, 3: string|null}>
	 */
	public static function responses(): array {
		return [
			'granted scopes win' => [['scope' => 'x,y z'], ['r'], ['x', 'y', 'z'], null],
			'list of scopes' => [['scope' => [' x ', '', 3, 'y']], ['r'], ['x', 'y'], null],
			'requested scopes when none granted' => [['scope' => 7], ['r' => 'r1', 's' => 's1'], ['r1', 's1'], null],
			'no expiry for zero' => [['expires_in' => 0], [], [], null],
			'no expiry for junk' => [['expires_in' => 'soon'], [], [], null],
			'expiry for an integer' => [['expires_in' => 3600], [], [], '2026-01-01T01:00:00+00:00'],
		];
	}

	/**
	 * @dataProvider responses
	 *
	 * @param array<string, mixed> $extra Extra response fields.
	 * @param array<int|string, string> $requested The requested scopes.
	 * @param array<int, string> $scopes The expected scopes.
	 * @param string|null $expiresAt The expected expiry.
	 */
	public function testTokenResponseScopesAndExpiry(array $extra, array $requested, array $scopes, ?string $expiresAt): void {
		$set = OAuth2TokenSet::fromTokenResponse(
			response: array_merge(['access_token' => 'AT'], $extra),
			requestedScopes: $requested,
			now: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$this->assertSame($scopes, $set->getScopes());
		$this->assertSame($expiresAt, $set->getExpiresAt()?->format(DATE_ATOM));
		$this->assertNull($set->getRefreshToken());
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function missingAccessTokens(): array {
		return ['absent' => [null], 'blank' => ['  '], 'not a string' => [42]];
	}

	/**
	 * @dataProvider missingAccessTokens
	 *
	 * @param mixed $token The access token value.
	 */
	public function testATokenResponseWithoutAnAccessTokenIsRefused(mixed $token): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('token response carries no access token');
		OAuth2TokenSet::fromTokenResponse(response: ['access_token' => $token]);
	}

	public function testAStoredDocumentIsReadWithEveryFallback(): void {
		$set = OAuth2TokenSet::fromStoredJson(
			stored: (string)json_encode(
				[
					'accessToken' => 'AT', 'refreshToken' => '', 'tokenType' => null, 'expiresAt' => 'garbage',
					'scopes' => 'a b', 'account' => 'nope', 'raw' => 'nope', 'obtainedAt' => '2026-01-01T00:00:00+00:00',
				]
			)
		);

		$this->assertSame(
			[
				'accessToken' => 'AT', 'refreshToken' => null, 'tokenType' => 'Bearer', 'expiresAt' => null, 'scopes' => ['a', 'b'],
				'account' => ['id' => '', 'handle' => '', 'displayName' => ''], 'raw' => [], 'obtainedAt' => '2026-01-01T00:00:00+00:00',
			],
			json_decode($set->toStoredJson(), true)
		);
	}

	public function testAStoredDocumentRoundTrips(): void {
		$document = [
			'accessToken' => 'AT', 'refreshToken' => 'RT', 'tokenType' => 'mac', 'expiresAt' => '2026-02-01T00:00:00+00:00',
			'scopes' => ['a'], 'account' => ['id' => '1', 'handle' => 'h', 'displayName' => 'd'], 'raw' => ['k' => 'v'],
			'obtainedAt' => '2026-01-01T00:00:00+00:00',
		];

		$this->assertSame($document, json_decode(OAuth2TokenSet::fromStoredJson(stored: (string)json_encode($document))->toStoredJson(), true));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function badDocuments(): array {
		return [
			'not json' => ['{', 'stored token set is not valid JSON'],
			'too deep' => [str_repeat('[', 40) . str_repeat(']', 40), 'stored token set is not valid JSON'],
			'scalar' => ['"AT"', 'stored token set carries no access token'],
			'no access token' => ['{"refreshToken":"RT_SECRET"}', 'stored token set carries no access token'],
			'access token not a string' => ['{"accessToken":5}', 'stored token set carries no access token'],
		];
	}

	/**
	 * @dataProvider badDocuments
	 *
	 * @param string $stored The stored document.
	 * @param string $message The expected refusal.
	 */
	public function testABadStoredDocumentIsRefusedWithoutQuotingIt(string $stored, string $message): void {
		try {
			OAuth2TokenSet::fromStoredJson(stored: $stored);
			$this->fail('bad document accepted');
		} catch (InvalidArgumentException $refused) {
			$this->assertSame($message, $refused->getMessage());
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, string>|null}>
	 */
	public static function identityAnswers(): array {
		$identity = ['path' => '/me', 'idField' => 'id', 'handleField' => 'a.b', 'displayNameField' => ''];

		return [
			'fields read' => [$identity, '{"id":5,"a":{"b":"h"}}', ['id' => '5', 'handle' => 'h', 'displayName' => '']],
			'missing and non-scalar fields' => [$identity, '{"id":{"x":1},"a":"flat"}', ['id' => '', 'handle' => '', 'displayName' => '']],
			'boolean field' => [$identity, '{"id":true,"a":{"b":null}}', ['id' => '1', 'handle' => '', 'displayName' => '']],
			'not json' => [$identity, 'nope', null],
			'json scalar' => [$identity, '"x"', null],
			'blank path' => [['path' => '  '], '{"id":"1"}', null],
			'identity not a block' => [['identity-is-a-string'], '{"id":"1"}', null],
		];
	}

	/**
	 * @dataProvider identityAnswers
	 *
	 * @param array<string, mixed> $identity The provider's identity block.
	 * @param string $body The identity answer.
	 * @param array<string, string>|null $account The account recorded, or null for none.
	 */
	public function testTheIdentityAnswerRecorded(array $identity, string $body, ?array $account): void {
		$calls = [];
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $requestBody = null, ?string $actingUserId = null) use (&$calls, $body): array {
				$calls[] = [$credentialId, $appId, $method, $path, $actingUserId];
				return ['status' => 200, 'headers' => [], 'body' => $body];
			}
		);

		$provider = ['identity' => $identity];
		if ($identity === ['identity-is-a-string']) {
			$provider = ['identity' => 'GET /me'];
		}

		$this->identity(broker: $broker)->record(
			provider: $provider,
			credentialId: 'cred-1',
			scope: 'organisation',
			set: OAuth2TokenSet::fromTokenResponse(response: ['access_token' => 'AT']),
			owner: 'alice'
		);

		if (trim((string)($identity['path'] ?? '')) === '') {
			$this->assertSame([], $calls);
		} else {
			$this->assertSame([['cred-1', 'openregister', 'GET', '/me', 'alice']], $calls);
		}

		if ($account === null) {
			$this->assertSame([], $this->persisted);
			return;
		}

		$this->assertCount(1, $this->persisted);
		$this->assertSame(['cred-1', 'organisation'], [$this->persisted[0][0], $this->persisted[0][1]]);
		$this->assertSame($account, $this->persisted[0][2]->getAccount());
		$this->assertSame('AT', $this->persisted[0][2]->getAccessToken());
	}

	public function testAFailedPersistIsSwallowed(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturn(['status' => 200, 'headers' => [], 'body' => '{"id":"1"}']);
		$refresh = $this->createMock(OAuth2RefreshService::class);
		$refresh->method('persist')->willThrowException(new \RuntimeException('custody down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new OAuth2AccountIdentity(broker: $broker, refresh: $refresh, logger: $logger))->record(
			provider: ['identity' => ['method' => 'POST', 'path' => '/me', 'idField' => 'id']],
			credentialId: 'c',
			scope: 'personal',
			set: OAuth2TokenSet::fromTokenResponse(response: ['access_token' => 'AT']),
			owner: 'alice'
		);
	}

	/**
	 * The identity reader over a scripted broker, recording what it persists.
	 *
	 * @param CredentialBrokerService $broker The broker.
	 *
	 * @return OAuth2AccountIdentity
	 */
	private function identity(CredentialBrokerService $broker): OAuth2AccountIdentity {
		$refresh = $this->createMock(OAuth2RefreshService::class);
		$refresh->method('persist')->willReturnCallback(function (string $credentialId, string $scope, OAuth2TokenSet $set): void {
			$this->persisted[] = [$credentialId, $scope, $set];
		});

		return new OAuth2AccountIdentity(broker: $broker, refresh: $refresh, logger: $this->createMock(LoggerInterface::class));
	}
}
