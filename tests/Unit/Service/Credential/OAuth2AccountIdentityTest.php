<?php

/**
 * OAuth2AccountIdentityTest — learning whose account a connection speaks for.
 *
 * A credential holding a live token and unable to say whose is a connection nobody
 * can audit: the owner sees a row saying "Mastodon" and cannot tell which of their
 * accounts it is. The panel would say "not connected yet" beside a working token,
 * forever, and nothing would fail.
 *
 * The two properties worth pinning are that the call goes through the broker's own
 * proxy, so it is bounded by the same allow-rules as anything else on that
 * credential, and that a failure here changes nothing. A connection that works must
 * not be undone because a label could not be read.
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
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-connection-records-the-account-it-speaks-for
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\OAuth2AccountIdentity;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2AccountIdentity
 */
class OAuth2AccountIdentityTest extends TestCase {
	/** @var array<int, array<string, mixed>> Every brokered call made. */
	private array $brokered = [];

	/** @var array<int, OAuth2TokenSet> Every token set persisted. */
	private array $persisted = [];

	protected function setUp(): void {
		$this->brokered = [];
		$this->persisted = [];
	}

	public function testTheHandleIsReadThroughTheBrokerAndRecordedOnTheCredential(): void {
		$this->makeIdentity()->record(
			provider: $this->mastodon(),
			credentialId: 'cred-1',
			scope: 'personal',
			set: $this->tokenSet(),
			owner: 'alice'
		);

		$this->assertCount(1, $this->brokered);
		$this->assertSame('/api/v1/accounts/verify_credentials', $this->brokered[0]['path']);
		$this->assertSame('GET', $this->brokered[0]['method']);
		$this->assertSame('openregister', $this->brokered[0]['appId']);
		$this->assertSame('alice', $this->brokered[0]['actingUserId']);

		$this->assertSame(
			['id' => '42', 'handle' => 'example@mastodon.example', 'displayName' => 'Example Reisbureau'],
			$this->persisted[0]->getAccount()
		);
	}

	public function testANestedFieldPathIsFollowed(): void {
		// X answers with the account under `data`, so a resolver that only read the
		// top level would record three empty strings and look like it worked.
		$identity = $this->makeIdentity(body: ['data' => ['id' => '7', 'username' => 'reisbureau', 'name' => 'Reisbureau']]);

		$identity->record(
			provider: [
				'identity' => [
					'method' => 'GET',
					'path' => '/2/users/me',
					'idField' => 'data.id',
					'handleField' => 'data.username',
					'displayNameField' => 'data.name',
				],
			],
			credentialId: 'cred-1',
			scope: 'personal',
			set: $this->tokenSet(),
			owner: 'alice'
		);

		$this->assertSame(
			['id' => '7', 'handle' => 'reisbureau', 'displayName' => 'Reisbureau'],
			$this->persisted[0]->getAccount()
		);
	}

	public function testAProviderThatDeclaresNoIdentityCallIsNotAsked(): void {
		$this->makeIdentity()->record(
			provider: ['identifier' => 'google-search-console'],
			credentialId: 'cred-1',
			scope: 'personal',
			set: $this->tokenSet(),
			owner: 'alice'
		);

		$this->assertSame([], $this->brokered);
		$this->assertSame([], $this->persisted);
	}

	public function testARefusedOrUnreachableIdentityCallRecordsNothingAndThrowsNothing(): void {
		$identity = $this->makeIdentity(requestThrows: new RuntimeException('the provider is having a day'));

		$identity->record(
			provider: $this->mastodon(),
			credentialId: 'cred-1',
			scope: 'personal',
			set: $this->tokenSet(),
			owner: 'alice'
		);

		$this->assertSame([], $this->persisted, 'a failed label read must leave the stored token set alone');
	}

	public function testAnAnswerThatIsNotJsonIsIgnoredRatherThanRecordedAsEmpty(): void {
		$identity = $this->makeIdentity(rawBody: '<html>rate limited</html>');

		$identity->record(
			provider: $this->mastodon(),
			credentialId: 'cred-1',
			scope: 'personal',
			set: $this->tokenSet(),
			owner: 'alice'
		);

		$this->assertSame([], $this->persisted);
	}

	/**
	 * The Mastodon identity declaration.
	 *
	 * @return array<string, mixed> The provider entry.
	 */
	private function mastodon(): array {
		return [
			'identifier' => 'mastodon',
			'identity' => [
				'method' => 'GET',
				'path' => '/api/v1/accounts/verify_credentials',
				'idField' => 'id',
				'handleField' => 'acct',
				'displayNameField' => 'display_name',
			],
		];
	}

	/**
	 * A stored token set to attach the account to.
	 *
	 * @return OAuth2TokenSet The set.
	 */
	private function tokenSet(): OAuth2TokenSet {
		return OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'ACCESS_TOKEN_HERE', 'expires_in' => 3600],
			requestedScopes: ['read:accounts']
		);
	}

	/**
	 * Build the service over a scripted broker.
	 *
	 * @param array<string, mixed>|null $body The decoded identity answer.
	 * @param string|null $rawBody A raw body to answer with instead.
	 * @param \Throwable|null $requestThrows What the broker throws instead of answering.
	 *
	 * @return OAuth2AccountIdentity The service under test.
	 */
	private function makeIdentity(
		?array $body = null,
		?string $rawBody = null,
		?\Throwable $requestThrows = null,
	): OAuth2AccountIdentity {
		$body ??= ['id' => '42', 'acct' => 'example@mastodon.example', 'display_name' => 'Example Reisbureau'];

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (
				string $credentialId,
				string $appId,
				string $method,
				string $path,
				array $headers = [],
				?string $requestBody = null,
				?string $actingUserId = null,
			) use ($body, $rawBody, $requestThrows): array {
				$this->brokered[] = [
					'credentialId' => $credentialId,
					'appId' => $appId,
					'method' => $method,
					'path' => $path,
					'actingUserId' => $actingUserId,
				];
				if ($requestThrows !== null) {
					throw $requestThrows;
				}

				return [
					'status' => 200,
					'headers' => [],
					'body' => ($rawBody ?? (string)json_encode($body)),
				];
			}
		);

		$refresh = $this->createMock(OAuth2RefreshService::class);
		$refresh->method('persist')->willReturnCallback(
			function (string $credentialId, string $scope, OAuth2TokenSet $set): void {
				$this->persisted[] = $set;
			}
		);

		return new OAuth2AccountIdentity(
			broker: $broker,
			refresh: $refresh,
			logger: $this->createMock(LoggerInterface::class)
		);
	}
}
