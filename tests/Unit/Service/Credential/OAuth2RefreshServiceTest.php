<?php

/**
 * OAuth2RefreshServiceTest — refresh, rotation, and what a failure means.
 *
 * The four behaviours pinned here are the ones a wrong guess about would be
 * expensive rather than merely wrong:
 *
 *   - a token inside its margin causes NO token request, so a healthy connection
 *     costs nothing on the read path;
 *   - the custody write happens BEFORE the object update, so a failed custody write
 *     never leaves an object claiming a token the leaf does not hold;
 *   - `invalid_grant` is terminal exactly once, and a transport failure is NOT,
 *     because treating a provider outage as a revoked grant would relink every
 *     connection on the instance during somebody else's incident;
 *   - two concurrent callers perform ONE exchange.
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
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialLock;
use OCA\OpenRegister\Service\Credential\CredentialRelinkNotifier;
use OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\OAuth2ClientResolver;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2RefreshService
 */
class OAuth2RefreshServiceTest extends TestCase {
	/** @var array<string, string> The fake custody leaf, keyed by credential UUID. */
	private array $vault = [];

	/** @var integer How many token requests were made. */
	private int $exchanges = 0;

	/** @var array<int, array<string, mixed>> Metadata written back onto the object. */
	private array $metadataWrites = [];

	/** @var array<int, array<string, string>> Relink announcements made. */
	private array $announcements = [];

	protected function setUp(): void {
		$this->vault = [];
		$this->exchanges = 0;
		$this->metadataWrites = [];
		$this->announcements = [];
	}

	public function testATokenInsideItsMarginCausesNoTokenRequest(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 3600);
		$service = $this->makeService(response: ['access_token' => 'SHOULD_NOT_BE_USED']);

		$token = $service->accessTokenFor(
			credential: $this->credential(),
			provider: $this->provider(),
			credentialId: 'cred-1',
			scope: 'personal'
		);

		$this->assertSame('ACCESS_TOKEN_HERE', $token);
		$this->assertSame(0, $this->exchanges, 'a healthy token must not cost a token request');
	}

	public function testATokenPastItsMarginIsRefreshedAndRotated(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 10);
		$service = $this->makeService(
			response: ['access_token' => 'ROTATED_ACCESS', 'expires_in' => 3600]
		);

		$token = $service->accessTokenFor(
			credential: $this->credential(),
			provider: $this->provider(),
			credentialId: 'cred-1',
			scope: 'personal'
		);

		$this->assertSame('ROTATED_ACCESS', $token);
		$this->assertSame(1, $this->exchanges);

		$stored = OAuth2TokenSet::fromStoredJson(stored: $this->vault['cred-1']);
		$this->assertSame('ROTATED_ACCESS', $stored->getAccessToken());
		$this->assertSame('REFRESH_TOKEN_HERE', $stored->getRefreshToken(), 'a rotation that issues no new refresh token keeps the old one');
	}

	public function testTheCustodyWriteHappensBeforeTheObjectUpdate(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 10);
		$order = [];

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturnCallback(fn (string $uuid): ?string => ($this->vault[$uuid] ?? null));
		$store->method('put')->willReturnCallback(
			function (string $uuid, string $secret) use (&$order): void {
				$order[] = 'custody';
				$this->vault[$uuid] = $secret;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function () use (&$order) {
				$order[] = 'object';
				return null;
			}
		);

		$service = $this->makeService(
			response: ['access_token' => 'ROTATED_ACCESS', 'expires_in' => 3600],
			store: $store,
			objectService: $objectService
		);

		$service->accessTokenFor(
			credential: $this->credential(),
			provider: $this->provider(),
			credentialId: 'cred-1',
			scope: 'personal'
		);

		$this->assertSame(['custody', 'object'], $order);
	}

	public function testAFailedCustodyWriteLeavesTheObjectUntouched(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 10);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturnCallback(fn (string $uuid): ?string => ($this->vault[$uuid] ?? null));
		$store->method('put')->willThrowException(new RuntimeException('custody leaf is down'));

		$service = $this->makeService(
			response: ['access_token' => 'ROTATED_ACCESS', 'expires_in' => 3600],
			store: $store
		);

		$this->expectException(RuntimeException::class);

		try {
			$service->accessTokenFor(
				credential: $this->credential(),
				provider: $this->provider(),
				credentialId: 'cred-1',
				scope: 'personal'
			);
		} finally {
			$this->assertSame([], $this->metadataWrites, 'a failed custody write must not move the object forward');
		}
	}

	public function testAnInvalidGrantRelinksNotifiesAndFailsClosed(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 10);
		$service = $this->makeService(response: ['error' => 'invalid_grant']);

		try {
			$service->accessTokenFor(
				credential: $this->credential(),
				provider: $this->provider(),
				credentialId: 'cred-1',
				scope: 'personal'
			);
			$this->fail('a revoked grant must raise the typed relink exception');
		} catch (CredentialRelinkRequiredException $expected) {
			$this->assertStringContainsString('invalid_grant', $expected->getMessage());
		}

		$this->assertSame('relink_needed', $this->metadataWrites[0]['status']);
		$this->assertSame('invalid_grant', $this->metadataWrites[0]['lastError']);
		$this->assertCount(1, $this->announcements, 'a lost grant is announced exactly once');
		$this->assertSame('invalid_grant', $this->announcements[0]['reason']);
		$this->assertSame('alice', $this->announcements[0]['owner']);
	}

	public function testARelinkedCredentialIsRefusedBeforeAnythingIsContacted(): void {
		$service = $this->makeService(response: ['access_token' => 'SHOULD_NOT_BE_USED']);

		$this->expectException(CredentialRelinkRequiredException::class);

		try {
			$service->accessTokenFor(
				credential: array_merge($this->credential(), ['status' => 'relink_needed']),
				provider: $this->provider(),
				credentialId: 'cred-1',
				scope: 'personal'
			);
		} finally {
			$this->assertSame(0, $this->exchanges, 'a dead grant must not be retried against the provider');
		}
	}

	public function testATransientFailureDoesNotRelink(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 10);
		$service = $this->makeService(throwOnExchange: new RuntimeException('connection timed out'));

		$this->expectException(CredentialUpstreamException::class);

		try {
			$service->accessTokenFor(
				credential: $this->credential(),
				provider: $this->provider(),
				credentialId: 'cred-1',
				scope: 'personal'
			);
		} finally {
			$this->assertSame([], $this->metadataWrites, 'a provider outage must not revoke a working connection');
			$this->assertSame([], $this->announcements);
		}
	}

	public function testNeitherTheEventNorTheNotificationCarriesATokenValue(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 10);
		$service = $this->makeService(response: ['error' => 'invalid_grant']);

		try {
			$service->accessTokenFor(
				credential: $this->credential(),
				provider: $this->provider(),
				credentialId: 'cred-1',
				scope: 'personal'
			);
		} catch (CredentialRelinkRequiredException) {
			// Expected; the assertions below are the point of this test.
		}

		$serialisedAnnouncement = (string)json_encode($this->announcements);
		$this->assertStringNotContainsString('ACCESS_TOKEN_HERE', $serialisedAnnouncement);
		$this->assertStringNotContainsString('REFRESH_TOKEN_HERE', $serialisedAnnouncement);

		$serialisedMetadata = json_encode($this->metadataWrites);
		$this->assertStringNotContainsString('ACCESS_TOKEN_HERE', (string)$serialisedMetadata);
		$this->assertStringNotContainsString('REFRESH_TOKEN_HERE', (string)$serialisedMetadata);
	}

	public function testAContendedRefreshPerformsOneExchange(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 10);

		// A lock already held by somebody else, who rotates the token while this
		// caller waits — which is exactly the sequence the read path is built for.
		$lock = $this->createMock(CredentialLock::class);
		$lock->method('acquire')->willReturn(false);
		$lock->method('waitForRelease')->willReturnCallback(
			function (): void {
				$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 3600, accessToken: 'ROTATED_BY_THE_HOLDER');
			}
		);

		$service = $this->makeService(response: ['access_token' => 'SHOULD_NOT_BE_USED'], lock: $lock);

		$token = $service->accessTokenFor(
			credential: $this->credential(),
			provider: $this->provider(),
			credentialId: 'cred-1',
			scope: 'personal'
		);

		$this->assertSame('ROTATED_BY_THE_HOLDER', $token);
		$this->assertSame(0, $this->exchanges, 'the waiter must re-read, not start a second exchange');
	}

	public function testTheSweepSkipsACredentialOutsideItsWindow(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 864000);
		$service = $this->makeService(response: ['access_token' => 'SHOULD_NOT_BE_USED']);

		$refreshed = $service->sweepCredential(
			credential: $this->credential(),
			provider: $this->provider(),
			credentialId: 'cred-1',
			scope: 'personal'
		);

		$this->assertFalse($refreshed);
		$this->assertSame(0, $this->exchanges);
	}

	public function testTheSweepRefreshesACredentialInsideItsWindow(): void {
		$this->vault['cred-1'] = $this->tokenSetJson(expiresIn: 3600);
		$service = $this->makeService(response: ['access_token' => 'SWEPT_ACCESS', 'expires_in' => 7200]);

		$refreshed = $service->sweepCredential(
			credential: $this->credential(),
			provider: $this->provider(),
			credentialId: 'cred-1',
			scope: 'personal'
		);

		$this->assertTrue($refreshed);
		$this->assertSame(1, $this->exchanges);
		$this->assertNotSame('', (string)($this->metadataWrites[0]['lastRefreshedAt'] ?? ''));
	}

	/**
	 * A stored token-set document.
	 *
	 * @param integer $expiresIn Seconds until the access token expires.
	 * @param string $accessToken The access token to store.
	 *
	 * @return string The document.
	 */
	private function tokenSetJson(int $expiresIn, string $accessToken = 'ACCESS_TOKEN_HERE'): string {
		return OAuth2TokenSet::fromTokenResponse(
			response: [
				'access_token' => $accessToken,
				'refresh_token' => 'REFRESH_TOKEN_HERE',
				'expires_in' => $expiresIn,
				'scope' => 'read:accounts write:statuses',
			],
			now: new DateTimeImmutable()
		)->toStoredJson();
	}

	/**
	 * The credential object's serialised data.
	 *
	 * @return array<string, mixed> The data.
	 */
	private function credential(): array {
		return [
			'name' => 'Mastodon company account',
			'provider' => 'mastodon',
			'owner' => 'alice',
			'kind' => 'oauth2-token-set',
			'status' => 'active',
			'instanceBaseUrl' => 'https://mastodon.example',
			'clientId' => 'CLIENT_ID_HERE',
		];
	}

	/**
	 * The catalogue provider entry.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function provider(): array {
		return [
			'identifier' => 'mastodon',
			'kind' => 'oauth2-token-set',
			'baseUrlFrom' => 'instanceBaseUrl',
			'oauth2' => [
				'tokenEndpoint' => '/oauth/token',
				'endpointsRelativeToInstance' => true,
				'refreshGrant' => 'refresh_token',
				'clientAuth' => 'client_secret_post',
			],
		];
	}

	/**
	 * Build the service under test with a scripted token endpoint.
	 *
	 * @param array<string, mixed> $response The token endpoint's decoded response.
	 * @param \Throwable|null $throwOnExchange A transport failure to raise instead.
	 * @param CredentialStore|null $store An override for the custody leaf.
	 * @param ObjectService|null $objectService An override for the object store.
	 * @param CredentialLock|null $lock An override for the lock.
	 *
	 * @return OAuth2RefreshService The service.
	 */
	private function makeService(
		array $response = [],
		?\Throwable $throwOnExchange = null,
		?CredentialStore $store = null,
		?ObjectService $objectService = null,
		?CredentialLock $lock = null,
	): OAuth2RefreshService {
		if ($store === null) {
			$store = $this->createMock(CredentialStore::class);
			$store->method('get')->willReturnCallback(fn (string $uuid): ?string => ($this->vault[$uuid] ?? null));
			$store->method('put')->willReturnCallback(
				function (string $uuid, string $secret): void {
					$this->vault[$uuid] = $secret;
				}
			);
		}

		if ($objectService === null) {
			$existing = new ObjectEntity();
			$existing->setUuid('cred-1');
			$existing->setObject($this->credential());

			$objectService = $this->createMock(ObjectService::class);
			$objectService->method('find')->willReturn($existing);
			$objectService->method('saveObject')->willReturnCallback(
				function (array | ObjectEntity $object): ObjectEntity {
					$this->metadataWrites[] = (is_array($object) === true ? $object : $object->getObject());
					return new ObjectEntity();
				}
			);
		}

		if ($lock === null) {
			$lock = $this->createMock(CredentialLock::class);
			$lock->method('acquire')->willReturn(true);
		}

		$clients = $this->createMock(OAuth2ClientResolver::class);
		$clients->method('resolve')->willReturn(['clientId' => 'CLIENT_ID_HERE', 'clientSecret' => 'YOUR_CLIENT_SECRET_HERE']);

		$httpResponse = $this->createMock(IResponse::class);
		$httpResponse->method('getBody')->willReturn((string)json_encode($response));

		$client = $this->createMock(IClient::class);
		if ($throwOnExchange !== null) {
			$client->method('post')->willThrowException($throwOnExchange);
		} else {
			$client->method('post')->willReturnCallback(
				function () use ($httpResponse): IResponse {
					$this->exchanges++;
					return $httpResponse;
				}
			);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$notifier = $this->createMock(CredentialRelinkNotifier::class);
		$notifier->method('announce')->willReturnCallback(
			function (string $credentialId, string $provider, string $owner, string $reason): void {
				$this->announcements[] = [
					'credentialId' => $credentialId,
					'provider' => $provider,
					'owner' => $owner,
					'reason' => $reason,
				];
			}
		);

		$service = new OAuth2RefreshService(
			credentialStore: $store,
			objectService: $objectService,
			lock: $lock,
			clients: $clients,
			clientService: $clientService,
			relinkNotifier: $notifier,
			logger: $this->createMock(LoggerInterface::class)
		);

		return $service;
	}
}
