<?php

/**
 * OAuth2RefreshServiceGoldenTest — pins every outcome of the token refresh before it is restructured.
 *
 * What a refactor of {@see \OCA\OpenRegister\Service\Credential\OAuth2RefreshService}
 * could quietly move: which statuses are refused, when the token endpoint is called
 * and with exactly what, which failures relink (and announce) a credential and which
 * are transient, the lock discipline, the sweep window, and the metadata mirrored
 * onto the credential object. Stored sets use fixed timestamps far in the past or
 * future, never "now plus a bit".
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialLock;
use OCA\OpenRegister\Service\Credential\CredentialRelinkNotifier;
use OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\OAuth2ClientResolver;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshExchange;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshStores;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshSteps;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshTarget;
use OCA\OpenRegister\Service\Credential\TokenSetAddress;
use OCA\OpenRegister\Event\CredentialRelinkRequiredEvent;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Golden outcomes of the OAuth2 token refresh.
 */
class OAuth2RefreshServiceGoldenTest extends TestCase {
	/** @var array<string, string> The custody leaf, by uuid. */
	private array $vault = [];

	/** @var array<int, string> Ordered side effects: get, put, post, save, announce, acquire, wait, release. */
	private array $events = [];

	/** @var array<int, array{0: string, 1: array<string, mixed>}> Every HTTP POST. */
	private array $posts = [];

	/** @var array<int, array<string, mixed>> Every object written. */
	private array $saves = [];

	/** @var array<int, array<string, string>> Every relink announcement. */
	private array $announcements = [];

	/** @var array<int, string|null> Every actingUserId the client resolver saw. */
	private array $actingUsers = [];

	/** @var string|\Throwable The token endpoint's body, or what it throws. */
	private string|\Throwable $tokenBody = '{"access_token":"NEW_AT","expires_in":3600}';

	/** @var array<int, bool> Scripted lock acquire answers, consumed in order. */
	private array $acquires = [true];

	/** @var string|null The client secret the resolver returns. */
	private ?string $clientSecret = 'SECRET';

	/** @var array<int, string|null> Stored documents returned by successive get() calls before the vault. */
	private array $reads = [];

	protected function setUp(): void {
		$this->vault = ['cred-1' => $this->stored(expiresAt: '2001-01-01T00:00:00+00:00')];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function blockingStatuses(): array {
		return ['relink needed' => ['relink_needed'], 'disabled' => ['disabled']];
	}

	/**
	 * @dataProvider blockingStatuses
	 *
	 * @param string $status The credential status.
	 */
	public function testABlockedCredentialIsRefusedBeforeAnythingIsRead(string $status): void {
		foreach (['accessTokenFor', 'sweepCredential'] as $method) {
			try {
				if ($method === 'accessTokenFor') {
					$address = new TokenSetAddress(credentialId: 'cred-1', scope: 'personal');
					$this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: ['status' => $status], provider: $this->provider(), address: $address, actingUserId: null));
				} else {
					$this->service()->sweepCredential(credential: ['status' => $status], provider: $this->provider(), credentialId: 'cred-1', scope: 'personal');
				}
				$this->fail('blocked credential accepted');
			} catch (CredentialRelinkRequiredException $refused) {
				$this->assertSame('credential cred-1 is ' . $status . ' and must be re-authorised', $refused->getMessage());
			}
		}

		$this->assertSame([], $this->events);
	}

	public function testAFreshTokenIsReturnedWithoutLockOrExchange(): void {
		$this->vault['cred-1'] = $this->stored(expiresAt: '2999-01-01T00:00:00+00:00');

		$this->assertSame('OLD_AT', $this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: $this->credential(), provider: $this->provider(), address: new TokenSetAddress(credentialId: 'cred-1', scope: 'personal'), actingUserId: null)));
		$this->assertSame(['get'], $this->events);
	}

	public function testAnExpiringTokenIsRotatedWithThePostedSecret(): void {
		$token = $this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: $this->credential(), provider: $this->provider(), address: new TokenSetAddress(credentialId: 'cred-1', scope: 'organisation'), actingUserId: 'bob'));

		$this->assertSame('NEW_AT', $token);
		$this->assertSame(['get', 'acquire', 'post', 'find', 'put', 'find', 'save', 'release'], $this->events);
		$this->assertSame(
			[['https://mastodon.example/oauth/token', [
				'body' => ['grant_type' => 'refresh_token', 'refresh_token' => 'OLD_RT', 'client_id' => 'CID', 'client_secret' => 'SECRET'],
				'headers' => ['Accept' => 'application/json'],
				'timeout' => 20,
			]]],
			$this->posts
		);
		$this->assertSame(['bob'], $this->actingUsers);

		$rotated = json_decode($this->vault['cred-1'], true);
		$this->assertSame('NEW_AT', $rotated['accessToken']);
		$this->assertSame('OLD_RT', $rotated['refreshToken']);
		$this->assertSame(['read'], $rotated['scopes']);

		$save = $this->saves[0];
		$this->assertIsString($save['lastRefreshedAt']);
		unset($save['lastRefreshedAt']);
		$this->assertSame('active', $save['status']);
		$this->assertSame('', $save['lastError']);
		$this->assertSame(['read'], $save['scopes']);
		$this->assertSame(['id' => '', 'handle' => '', 'displayName' => ''], $save['account']);
		$this->assertIsString($save['expiresAt']);
		$this->assertSame('mastodon', $save['provider']);
	}

	public function testBasicClientAuthAndTheMetaExchangeGrant(): void {
		$provider = $this->provider();
		$provider['oauth2'] = ['tokenEndpoint' => 'https://graph.example/token', 'clientAuth' => 'client_secret_basic', 'refreshGrant' => 'fb_exchange_token'];

		$this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: $this->credential(), provider: $provider, address: new TokenSetAddress(credentialId: 'cred-1', scope: 'personal'), actingUserId: null));

		$this->assertSame(
			[['https://graph.example/token', [
				'body' => ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => 'OLD_RT', 'client_id' => 'CID'],
				'headers' => ['Accept' => 'application/json'],
				'timeout' => 20,
				'auth' => ['CID', 'SECRET'],
			]]],
			$this->posts
		);
	}

	public function testAPublicClientSendsNoSecret(): void {
		$this->clientSecret = null;
		$this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: $this->credential(), provider: $this->provider(), address: new TokenSetAddress(credentialId: 'cred-1', scope: 'personal'), actingUserId: null));

		$this->assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'OLD_RT', 'client_id' => 'CID'], $this->posts[0][1]['body']);
		$this->assertArrayNotHasKey('auth', $this->posts[0][1]);
	}

	public function testAContendedRefreshReadsWhatTheOtherRotationStored(): void {
		$this->acquires = [false];
		$this->reads = [$this->stored(expiresAt: '2001-01-01T00:00:00+00:00'), $this->stored(expiresAt: '2999-01-01T00:00:00+00:00', accessToken: 'THEIR_AT')];

		$this->assertSame('THEIR_AT', $this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: $this->credential(), provider: $this->provider(), address: new TokenSetAddress(credentialId: 'cred-1', scope: 'personal'), actingUserId: null)));
		$this->assertSame(['get', 'acquire', 'wait', 'get'], $this->events);
	}

	public function testAContendedRefreshThatIsStillExpiringRotatesOnceTheLockIsFree(): void {
		$this->acquires = [false, true];

		$this->assertSame('NEW_AT', $this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: $this->credential(), provider: $this->provider(), address: new TokenSetAddress(credentialId: 'cred-1', scope: 'personal'), actingUserId: null)));
		$this->assertSame(['get', 'acquire', 'wait', 'get', 'acquire', 'post', 'find', 'put', 'find', 'save', 'release'], $this->events);
	}

	public function testAContendedRefreshWhoseLockStaysHeldIsTransient(): void {
		$this->acquires = [false, false];

		$this->expectException(CredentialUpstreamException::class);
		$this->expectExceptionMessage('refresh lock is held and the token is still expiring');
		$this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: $this->credential(), provider: $this->provider(), address: new TokenSetAddress(credentialId: 'cred-1', scope: 'personal'), actingUserId: null));
	}

	public function testASetWithoutARefreshTokenRelinks(): void {
		$this->vault['cred-1'] = $this->stored(expiresAt: '2001-01-01T00:00:00+00:00', refreshToken: null);

		$this->assertRefused(exception: CredentialRelinkRequiredException::class, message: 'credential cred-1 has no refresh token');
		$this->assertSame([], $this->posts);
		$this->assertRelinked(reason: 'no_refresh_token');
		$this->assertSame('release', end($this->events));
	}

	public function testAnInvalidGrantInTheBodyRelinks(): void {
		$this->tokenBody = '{"error":"invalid_grant"}';

		$this->assertRefused(exception: CredentialRelinkRequiredException::class, message: 'refresh refused: invalid_grant');
		$this->assertRelinked(reason: 'invalid_grant');
		$this->assertSame('release', end($this->events));
	}

	public function testAnInvalidGrantRaisedByTheTransportRelinks(): void {
		$this->tokenBody = $this->transportFailure(body: '{"error":"invalid_grant","error_description":"RT_SECRET revoked"}');

		$refused = $this->assertRefused(exception: CredentialRelinkRequiredException::class, message: 'refresh refused: invalid_grant');
		$this->assertSame($this->tokenBody, $refused->getPrevious());
		$this->assertRelinked(reason: 'invalid_grant');
	}

	/**
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function transientTransportFailures(): array {
		return [
			'no response' => [null, 'token endpoint unreachable or failing: '],
			'non-json response' => ['<html>', 'token endpoint unreachable or failing: '],
			'other error' => ['{"error":"slow_down"}', 'token endpoint unreachable or failing: '],
		];
	}

	/**
	 * @dataProvider transientTransportFailures
	 *
	 * @param string|null $body The failure's response body, or null for none.
	 * @param string $prefix The expected message prefix.
	 */
	public function testATransientTransportFailureDoesNotRelink(?string $body, string $prefix): void {
		$this->tokenBody = $this->transportFailure(body: $body);

		$refused = $this->assertRefused(exception: CredentialUpstreamException::class, message: null);
		$this->assertSame($prefix . $this->tokenBody::class, $refused->getMessage());
		$this->assertSame([], $this->announcements);
		$this->assertSame([], $this->saves);
	}

	public function testAPlainTransportFailureDoesNotRelink(): void {
		$this->tokenBody = new \LogicException('connection reset');

		$this->assertRefused(exception: CredentialUpstreamException::class, message: 'token endpoint unreachable or failing: LogicException');
		$this->assertSame([], $this->announcements);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function badBodies(): array {
		return [
			'not json' => ['nope', 'token endpoint returned a body that is not JSON'],
			'other error' => ['{"error":"temporarily_unavailable"}', 'token endpoint returned error temporarily_unavailable'],
		];
	}

	/**
	 * @dataProvider badBodies
	 *
	 * @param string $body The token endpoint's body.
	 * @param string $message The expected refusal.
	 */
	public function testABadTokenBodyIsTransient(string $body, string $message): void {
		$this->tokenBody = $body;

		$this->assertRefused(exception: CredentialUpstreamException::class, message: $message);
		$this->assertSame([], $this->announcements);
		$this->assertSame([], $this->saves);
	}

	public function testAProviderWithoutATokenEndpointIsRefused(): void {
		$provider = $this->provider();
		unset($provider['oauth2']['tokenEndpoint']);

		$this->assertRefused(exception: CredentialAccessDeniedException::class, message: 'no token endpoint in the catalogue for credential cred-1', provider: $provider);
		$this->assertSame([], $this->posts);

		$this->assertRefused(exception: CredentialAccessDeniedException::class, message: 'no token endpoint in the catalogue for credential cred-1', provider: ['oauth2' => 'nope']);
	}

	public function testACredentialWithoutAUsableHostIsRefused(): void {
		$credential = $this->credential();
		$credential['instanceBaseUrl'] = 'http://127.0.0.1';

		$refused = $this->assertRefused(exception: CredentialAccessDeniedException::class, message: null, credential: $credential);
		$this->assertStringStartsWith('credential cred-1 has no usable instance host: ', $refused->getMessage());
		$this->assertSame([], $this->posts);
	}

	/**
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function unusableStoredSets(): array {
		return [
			'nothing' => [null, 'no token set stored for credential'],
			'empty' => ['', 'no token set stored for credential'],
			'not json' => ['{', 'stored token set is unusable: stored token set is not valid JSON'],
			'no access token' => ['{"refreshToken":"RT"}', 'stored token set is unusable: stored token set carries no access token'],
		];
	}

	/**
	 * @dataProvider unusableStoredSets
	 *
	 * @param string|null $stored The stored document.
	 * @param string $message The expected refusal.
	 */
	public function testAnUnusableStoredSetIsRefused(?string $stored, string $message): void {
		$this->vault = [];
		if ($stored !== null) {
			$this->vault['cred-1'] = $stored;
		}

		try {
			$this->service()->storedSet(credentialId: 'cred-1', scope: 'personal');
			$this->fail('unusable set accepted');
		} catch (CredentialAccessDeniedException $refused) {
			$this->assertSame($message, $refused->getMessage());
		}
	}

	public function testTheSweepWindowAndLock(): void {
		$this->vault['cred-1'] = $this->stored(expiresAt: '2999-01-01T00:00:00+00:00');
		$this->assertFalse($this->service()->sweepCredential(credential: $this->credential(), provider: $this->provider(), credentialId: 'cred-1', scope: 'personal'));
		$this->assertSame(['get'], $this->events);

		$this->events = [];
		$this->vault['cred-1'] = $this->stored(expiresAt: '2001-01-01T00:00:00+00:00');
		$this->acquires = [false];
		$this->assertFalse($this->service()->sweepCredential(credential: $this->credential(), provider: $this->provider(), credentialId: 'cred-1', scope: 'personal'));
		$this->assertSame(['get', 'acquire'], $this->events);
	}

	public function testTheSweepRotatesAsTheOwner(): void {
		$this->assertTrue($this->service()->sweepCredential(credential: $this->credential(), provider: $this->provider(), credentialId: 'cred-1', scope: 'personal'));

		$credential = $this->credential();
		$credential['owner'] = '  ';
		$this->acquires = [true];
		$this->vault['cred-1'] = $this->stored(expiresAt: '2001-01-01T00:00:00+00:00');
		$this->service()->sweepCredential(credential: $credential, provider: $this->provider(), credentialId: 'cred-1', scope: 'personal');

		$this->assertSame(['alice', null], $this->actingUsers);
		$this->assertSame('release', end($this->events));
	}

	public function testPersistMirrorsAnActiveStatusAndWritesPastAccessControl(): void {
		$this->service()->persist(
			address: new \OCA\OpenRegister\Service\Credential\TokenSetAddress(credentialId: 'cred-1', scope: 'personal'),
			set: \OCA\OpenRegister\Service\Credential\OAuth2TokenSet::fromStoredJson(stored: $this->stored(expiresAt: '2030-01-01T00:00:00+00:00'))
		);

		$this->assertSame(['find', 'put', 'find', 'save'], $this->events);
		$save = $this->saves[0];
		$this->assertSame('active', $save['status']);
		$this->assertSame('', $save['lastError']);
		$this->assertSame('2030-01-01T00:00:00+00:00', $save['expiresAt']);
		$this->assertSame(['register' => 'credential-broker', 'schema' => 'brokeredcredential', 'uuid' => 'cred-1', '_rbac' => false, '_multitenancy' => false], $save['@args']);
	}

	/**
	 * Call accessTokenFor() expecting a refusal.
	 *
	 * @param class-string<\Throwable> $exception The expected type.
	 * @param string|null $message The expected message, or null to skip.
	 * @param array<string, mixed>|null $provider The provider override.
	 * @param array<string, mixed>|null $credential The credential override.
	 *
	 * @return \Throwable The refusal.
	 */
	private function assertRefused(string $exception, ?string $message, ?array $provider = null, ?array $credential = null): \Throwable {
		try {
			$this->service()->accessTokenFor(target: new OAuth2RefreshTarget(credential: ($credential ?? $this->credential()), provider: ($provider ?? $this->provider()), address: new TokenSetAddress(credentialId: 'cred-1', scope: 'personal'), actingUserId: null));
		} catch (\Throwable $refused) {
			$this->assertInstanceOf($exception, $refused);
			if ($message !== null) {
				$this->assertSame($message, $refused->getMessage());
			}

			$this->assertStringNotContainsString('RT_SECRET', $refused->getMessage());
			$this->assertStringNotContainsString('OLD_RT', $refused->getMessage());
			return $refused;
		}

		$this->fail('refresh accepted');
	}

	/**
	 * Assert the credential was moved to relink_needed and announced, once.
	 *
	 * @param string $reason The expected reason.
	 *
	 * @return void
	 */
	private function assertRelinked(string $reason): void {
		$this->assertCount(1, $this->saves);
		$this->assertSame('relink_needed', $this->saves[0]['status']);
		$this->assertSame($reason, $this->saves[0]['lastError']);
		$this->assertSame([['credentialId' => 'cred-1', 'provider' => 'mastodon', 'owner' => 'alice', 'reason' => $reason]], $this->announcements);
		$this->assertLessThan(array_search('announce', $this->events, true), array_search('save', $this->events, true));
	}

	/**
	 * A transport failure carrying a response body, as the NC client raises it.
	 *
	 * @param string|null $body The response body, or null for no response.
	 *
	 * @return \RuntimeException
	 */
	private function transportFailure(?string $body): \RuntimeException {
		$response = null;
		if ($body !== null) {
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn($body);
		}

		return new class ('400 Bad Request', $response) extends \RuntimeException {
			/**
			 * @param string $message The message.
			 * @param IResponse|null $response The response.
			 */
			public function __construct(string $message, private readonly ?IResponse $response) {
				parent::__construct($message);
			}

			/**
			 * @return IResponse|null
			 */
			public function getResponse(): ?IResponse {
				return $this->response;
			}
		};
	}

	/**
	 * A stored token-set document with fixed timestamps.
	 *
	 * @param string $expiresAt The expiry.
	 * @param string|null $refreshToken The refresh token.
	 * @param string $accessToken The access token.
	 *
	 * @return string
	 */
	private function stored(string $expiresAt, ?string $refreshToken = 'OLD_RT', string $accessToken = 'OLD_AT'): string {
		return (string)json_encode(
			[
				'accessToken' => $accessToken, 'refreshToken' => $refreshToken, 'tokenType' => 'Bearer', 'expiresAt' => $expiresAt,
				'scopes' => ['read'], 'account' => [], 'raw' => [], 'obtainedAt' => '2000-01-01T00:00:00+00:00',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function credential(): array {
		return ['provider' => 'mastodon', 'owner' => 'alice', 'status' => 'active', 'instanceBaseUrl' => 'https://mastodon.example', 'clientId' => 'CID'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function provider(): array {
		return [
			'identifier' => 'mastodon',
			'oauth2' => ['tokenEndpoint' => '/oauth/token', 'endpointsRelativeToInstance' => true, 'clientAuth' => 'client_secret_post'],
		];
	}

	/**
	 * Build the service from scripted leaf collaborators.
	 *
	 * @return OAuth2RefreshService
	 */
	private function service(): OAuth2RefreshService {
		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturnCallback(function (string $uuid): ?string {
			$this->events[] = 'get';
			if ($this->reads !== []) {
				return array_shift($this->reads);
			}

			return ($this->vault[$uuid] ?? null);
		});
		$store->method('put')->willReturnCallback(function (string $uuid, string $secret): void {
			$this->events[] = 'put';
			$this->vault[$uuid] = $secret;
		});

		$entity = new ObjectEntity();
		$entity->setUuid('cred-1');
		$entity->setObject($this->credential());
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(function () use ($entity): ObjectEntity {
			$this->events[] = 'find';
			return $entity;
		});
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->events[] = 'save';
				$object['@args'] = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				$this->saves[] = $object;
				return new ObjectEntity();
			}
		);

		$lock = $this->createMock(CredentialLock::class);
		$lock->method('acquire')->willReturnCallback(function (): bool {
			$this->events[] = 'acquire';
			return (array_shift($this->acquires) ?? true);
		});
		$lock->method('waitForRelease')->willReturnCallback(function (): void {
			$this->events[] = 'wait';
		});
		$lock->method('release')->willReturnCallback(function (): void {
			$this->events[] = 'release';
		});

		$clients = $this->createMock(OAuth2ClientResolver::class);
		$clients->method('resolve')->willReturnCallback(function (array $credential, string $provider, ?string $actingUserId = null): array {
			$this->actingUsers[] = $actingUserId;
			return ['clientId' => 'CID', 'clientSecret' => $this->clientSecret];
		});

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options): IResponse {
			$this->events[] = 'post';
			$this->posts[] = [$url, $options];
			if ($this->tokenBody instanceof \Throwable) {
				throw $this->tokenBody;
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn($this->tokenBody);
			return $response;
		});
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		$notifier = $this->createMock(CredentialRelinkNotifier::class);
		$notifier->method('announce')->willReturnCallback(function (CredentialRelinkRequiredEvent $event): void {
			$this->events[] = 'announce';
			$this->announcements[] = [
				'credentialId' => $event->getCredentialId(),
				'provider' => $event->getProvider(),
				'owner' => $event->getOwner(),
				'reason' => $event->getReason(),
			];
		});

		return $this->build(store: $store, objects: $objects, lock: $lock, clients: $clients, http: $http, notifier: $notifier);
	}

	/**
	 * Wire the service the way the container does.
	 *
	 * @param CredentialStore $store The custody leaf.
	 * @param ObjectOperationsInterface $objects The object store.
	 * @param CredentialLock $lock The lock.
	 * @param OAuth2ClientResolver $clients The client resolver.
	 * @param IClientService $http The HTTP client factory.
	 * @param CredentialRelinkNotifier $notifier The relink notifier.
	 *
	 * @return OAuth2RefreshService
	 */
	private function build(
		CredentialStore $store,
		ObjectOperationsInterface $objects,
		CredentialLock $lock,
		OAuth2ClientResolver $clients,
		IClientService $http,
		CredentialRelinkNotifier $notifier,
	): OAuth2RefreshService {
		return new OAuth2RefreshService(
			stores: new OAuth2RefreshStores(
				credentialStore: $store,
				objectService: $objects,
			),
			lock: $lock,
			steps: new OAuth2RefreshSteps(
				exchange: new OAuth2RefreshExchange(clients: $clients, clientService: $http),
				relinkNotifier: $notifier,
			),
			logger: $this->createMock(LoggerInterface::class),
		);
	}
}
