<?php

/**
 * AuthorizationService as a public, hardened entry point (gate 23 gap 2, integriq I1a).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\Consumer\ConsumerSource;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * integriq authenticates inbound calls with its own copy of this class because
 * OpenRegister's could not do what integriq needs: RS/PS tokens were refused
 * ("Asymmetric verification not yet implemented") although the auth-system
 * spec already says they are verified, a reused jti and an iat in the future
 * were accepted, the entry points were protected, and nothing told the caller
 * which consumer authenticated. Every test here runs the real service; only
 * Nextcloud's user, group, session, request and cache objects are doubles.
 */
class AuthorizationServiceHardeningTest extends TestCase {

	/**
	 * The RSA public key of the PS256 fixture.
	 *
	 * @var string
	 */
	private const PS256_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA7T0ZiN3R/9WQI49CqBQW
y3VlhZ6749tt3fOw5krZ4PuDaohgPZng7VLBEiqYDlb0OGzbJgnQzVLHOTeHSgus
uT70SLpQoftyfvVCk6djSby9TpnYTaVD6Ofk7S9lAGAB5od+3PE5Ry1JDg+mfE3g
z4ugYYBWVf60SE8hHiA95yxJTFpCsQK9JXzwmoU+n+GcDHpk0Dy4puNFI73hqYh5
e/7kCyCvikOVRVRvhTyNYy5j+/A3ayaHdlNqcBcR+RWxvjFibWMbswukpDx/p0vl
MltxFpnGncGb+q6lHVm8SySxWTagvjG/JiCpKwybYAHvEKlo1sCrhXeJTOS1CDuV
kQIDAQAB
-----END PUBLIC KEY-----
PEM;

	/**
	 * A PS256 token for issuer zaaksysteem, signed by the fixture key outside PHP.
	 *
	 * @var string
	 */
	private const PS256_TOKEN = 'eyJhbGciOiJQUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJ6YWFrc3lzdGVlbSIsImlhdCI6MTc1OTYyMjQwMCwiZXhwIjo0MTAyNDQ0ODAwfQ.t64NPJ6Grr8-n4syEr6r-1Fr8W9tVWXgC3vQNZ5JAMfMeoQLARudI1i0Fuga6E3Y2Uh-YDihZgXTZ3vWlrcBhy98MVGFvxqd80u9O4QyFdF0ReM5zXYBXtcSGGw2F2_eGzcxwV7pAxy5Ko3IOLHkCZdffqCQMDc11sB5ZGdxe7blqZUQtKQj5zPlILz-FHrvYBRfwoqbpk71gWNBBVhJR5roTfRv41V4AUz_ANPAxN_wY9Htxoz86bwhBJ4VDWBkO_RNaxazCj7ZB9RW4YWs4Rm7UQxTzyzf5DdPGuicFKU9yFOu17nVf57NR5cvKiPJVxroiJSVWdJDysA3lvkqpQ';

	/** @var array<string, mixed> What the jti cache holds. */
	private array $cache = [];

	/** @var array<int, IUser|null> The users the session was given, request-scoped. */
	private array $volatileUsers = [];

	/** @var int How often the persistent setUser() was called. */
	private int $persistentSets = 0;

	/** @var array<int, Consumer> The consumers in OpenRegister's own table. */
	private array $consumers = [];

	private string $authorizationHeader = '';

	private bool $loggedIn = false;

	private ?IUser $sessionUser = null;

	private bool $passesCsrf = true;

	/**
	 * The real service over doubles for Nextcloud.
	 *
	 * @return AuthorizationService
	 */
	private function service(): AuthorizationService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('api-user');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$users->method('checkPassword')->willReturnCallback(
			fn (string $login, string $password) => ($password === 'secret') ? $this->userNamed($login) : false
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->volatileUsers[] = $user;
			}
		);
		$session->method('setUser')->willReturnCallback(
			function (): void {
				$this->persistentSets++;
			}
		);
		$session->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->loggedIn);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);

		$mapper = $this->createMock(ConsumerMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = []): array {
				if (isset($filters['name']) === false) {
					return $this->consumers;
				}

				return array_values(array_filter($this->consumers, fn (Consumer $c): bool => $c->getName() === $filters['name']));
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cache[$key] = $value;
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$staff = $this->createMock(IGroup::class);
		$staff->method('getGID')->willReturn('staff');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturnCallback(
			fn (IUser $user) => ($user->getUID() === 'piet') ? [$staff] : []
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name) => (strtolower($name) === 'authorization') ? $this->authorizationHeader : ''
		);
		$request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->passesCsrf);

		return new AuthorizationService(
			userManager: $users,
			userSession: $session,
			consumerMapper: $mapper,
			cacheFactory: $cacheFactory,
			groupManager: $groups,
			request: $request,
		);
	}//end service()

	/**
	 * A user double with a uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function userNamed(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn(null);
		return $user;
	}//end userNamed()

	/**
	 * A consumer in OpenRegister's table.
	 *
	 * @param string $algorithm The pinned algorithm.
	 * @param string $publicKey The key or secret.
	 *
	 * @return Consumer
	 */
	private function consumer(string $algorithm, string $publicKey): Consumer {
		$consumer = new Consumer();
		$consumer->setUuid('c0ffee00-0000-4000-8000-000000000001');
		$consumer->setName('zaaksysteem');
		$consumer->setUserId('api-user');
		$consumer->setAuthorizationType('jwt');
		$consumer->setAuthorizationConfiguration(['algorithm' => $algorithm, 'publicKey' => $publicKey]);
		$this->consumers[] = $consumer;
		return $consumer;
	}//end consumer()

	/**
	 * A compact JWS signed with the given key.
	 *
	 * @param \Jose\Component\Core\JWK $key       The signing key.
	 * @param string                   $algorithm The algorithm.
	 * @param array<string, mixed>     $claims    Claims over the defaults.
	 *
	 * @return string The token.
	 */
	private function token(\Jose\Component\Core\JWK $key, string $algorithm, array $claims = []): string {
		$builder = new JWSBuilder(new AlgorithmManager([new HS256(), new RS256()]));
		$jws = $builder->create()
			->withPayload(json_encode(array_merge(['iss' => 'zaaksysteem', 'iat' => time()], $claims)))
			->addSignature($key, ['alg' => $algorithm, 'typ' => 'JWT'])
			->build();
		return (new CompactSerializer())->serialize($jws, 0);
	}//end token()

	/**
	 * An RSA key pair: the private JWK and the public key as PEM.
	 *
	 * @return array{0: \Jose\Component\Core\JWK, 1: string}
	 */
	private function rsa(): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $privatePem);
		$publicPem = openssl_pkey_get_details($key)['key'];
		return [JWKFactory::createFromKey($privatePem), $publicPem];
	}//end rsa()

	public function testAnRs256TokenIsVerifiedWithThePublicKey(): void {
		[$private, $pem] = $this->rsa();
		$this->consumer('RS256', $pem);

		$this->service()->authorizeJwt('Bearer ' . $this->token($private, 'RS256'));

		$this->assertCount(1, $this->volatileUsers, 'An RS256 token signed by the configured key must authenticate.');
	}//end testAnRs256TokenIsVerifiedWithThePublicKey()

	public function testAPs256TokenIsVerifiedAndABase64PemIsAccepted(): void {
		// A fixed token made with `openssl dgst -sha256 -sigopt rsa_padding_mode:pss
		// -sigopt rsa_pss_saltlen:32`: PSS signing in pure PHP (no gmp) takes
		// minutes, verifying is quick. iat 2025-10-05, exp 2100 (no lifetime cap, Q4).
		$this->consumer('PS256', base64_encode(self::PS256_PUBLIC_KEY));

		$this->service()->authorizeJwt('Bearer ' . self::PS256_TOKEN);

		$this->assertCount(1, $this->volatileUsers);
	}//end testAPs256TokenIsVerifiedAndABase64PemIsAccepted()

	public function testAnRs256TokenSignedByAnotherKeyIsRefused(): void {
		[, $pem] = $this->rsa();
		[$other] = $this->rsa();
		$this->consumer('RS256', $pem);

		$this->expectException(AuthenticationException::class);
		$this->service()->authorizeJwt('Bearer ' . $this->token($other, 'RS256'));
	}//end testAnRs256TokenSignedByAnotherKeyIsRefused()

	public function testAnHmacTokenAgainstAnRsaConsumerIsRefused(): void {
		[, $pem] = $this->rsa();
		$this->consumer('RS256', $pem);

		$this->expectException(AuthenticationException::class);
		$this->service()->authorizeJwt('Bearer ' . $this->token(JWKFactory::createFromSecret($pem), 'HS256'));
	}//end testAnHmacTokenAgainstAnRsaConsumerIsRefused()

	public function testAReusedJtiIsRefused(): void {
		$secret = str_repeat('k', 32);
		$this->consumer('HS256', $secret);
		$token = $this->token(JWKFactory::createFromSecret($secret), 'HS256', ['jti' => 'once']);
		$service = $this->service();

		$service->authorizeJwt('Bearer ' . $token);
		try {
			$service->authorizeJwt('Bearer ' . $token);
			$this->fail('The same jti a second time must be refused.');
		} catch (AuthenticationException $e) {
			$this->assertStringContainsString('already been used', $e->getMessage());
		}
	}//end testAReusedJtiIsRefused()

	public function testAnIatInTheFutureIsRefused(): void {
		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('invalid issue time');
		$this->service()->validatePayload(['iat' => time() + 600, 'exp' => time() + 1200]);
	}//end testAnIatInTheFutureIsRefused()

	public function testALongLifetimeIsStillAccepted(): void {
		// The 3600 s cap integriq applies is an open question (Q4): not built here.
		$this->service()->validatePayload(['iat' => time(), 'exp' => time() + 86400]);
		$this->assertTrue(true);
	}//end testALongLifetimeIsStillAccepted()

	public function testTheConsumerThatAuthenticatedIsReadBack(): void {
		$secret = str_repeat('k', 32);
		$this->consumer('HS256', $secret);
		$service = $this->service();
		$this->assertNull($service->getResolvedConsumer());

		$service->authorizeJwt('Bearer ' . $this->token(JWKFactory::createFromSecret($secret), 'HS256'));

		$resolved = $service->getResolvedConsumer();
		$this->assertInstanceOf(ResolvedConsumer::class, $resolved);
		$this->assertSame('zaaksysteem', $resolved->name);
		$this->assertSame('c0ffee00-0000-4000-8000-000000000001', $resolved->uuid);
	}//end testTheConsumerThatAuthenticatedIsReadBack()

	public function testACallerCanBringItsOwnConsumerSource(): void {
		$secret = str_repeat('s', 32);
		$source = new class($secret) implements ConsumerSource {
			public function __construct(private string $secret) {
			}

			public function findByIssuer(string $issuer): ?ResolvedConsumer {
				return new ResolvedConsumer(
					source: 'integriq',
					uuid: 'app-consumer-1',
					name: $issuer,
					userId: 'api-user',
					authorizationType: 'jwt',
					configuration: ['algorithm' => 'HS256', 'publicKey' => $this->secret],
					record: ['from' => 'an app schema']
				);
			}

			public function findByApiKey(string $apiKey): ?ResolvedConsumer {
				return null;
			}
		};
		$service = $this->service();

		$service->authorizeJwt('Bearer ' . $this->token(JWKFactory::createFromSecret($secret), 'HS256'), $source);

		$this->assertSame('integriq', $service->getResolvedConsumer()?->source);
		$this->assertSame(['from' => 'an app schema'], $service->getResolvedConsumer()?->record);
	}//end testACallerCanBringItsOwnConsumerSource()

	public function testAConsumerApiKeyAuthenticatesAndIsReadBack(): void {
		$consumer = new Consumer();
		$consumer->setUuid('c0ffee00-0000-4000-8000-000000000002');
		$consumer->setName('portaal');
		$consumer->setUserId('api-user');
		$consumer->setAuthorizationType('apiKey');
		$consumer->setAuthorizationConfiguration(['apiKey' => 'k-123']);
		$this->consumers[] = $consumer;
		$service = $this->service();

		$service->authorizeApiKey('k-123', []);

		$this->assertSame('portaal', $service->getResolvedConsumer()?->name);
		$this->assertCount(1, $this->volatileUsers);
	}//end testAConsumerApiKeyAuthenticatesAndIsReadBack()

	public function testAnUnknownApiKeyIsRefused(): void {
		$this->expectException(AuthenticationException::class);
		$this->service()->authorizeApiKey('nope', ['k-1' => 'api-user']);
	}//end testAnUnknownApiKeyIsRefused()

	public function testBasicHonoursTheAllowList(): void {
		$service = $this->service();
		$service->authorizeBasic('Basic ' . base64_encode('piet:secret'), [], ['staff']);
		$this->assertCount(1, $this->volatileUsers, 'piet is in staff.');

		$this->expectException(AuthenticationException::class);
		$service->authorizeBasic('Basic ' . base64_encode('klaas:secret'), [], ['staff']);
	}//end testBasicHonoursTheAllowList()

	public function testOAuthRefusesASessionCookieWithoutABearerHeader(): void {
		$this->loggedIn = true;
		$this->sessionUser = $this->userNamed('piet');
		$this->authorizationHeader = '';

		$this->expectException(AuthenticationException::class);
		$this->service()->authorizeOAuth('Bearer forged', [], []);
	}//end testOAuthRefusesASessionCookieWithoutABearerHeader()

	public function testOAuthAcceptsTheBearerTheRequestCarried(): void {
		$this->loggedIn = true;
		$this->sessionUser = $this->userNamed('piet');
		$this->authorizationHeader = 'Bearer real-token';

		$this->service()->authorizeOAuth('Bearer real-token', [], ['staff']);
		$this->assertTrue(true);
	}//end testOAuthAcceptsTheBearerTheRequestCarried()

	public function testACredentialNeverWritesTheIdentityIntoTheSession(): void {
		$secret = str_repeat('k', 32);
		$this->consumer('HS256', $secret);

		$this->service()->authorizeJwt('Bearer ' . $this->token(JWKFactory::createFromSecret($secret), 'HS256'));

		$this->assertSame(0, $this->persistentSets, 'setUser() persists the identity into the caller\'s PHP session (ADR-099).');
		$this->assertCount(1, $this->volatileUsers);
	}//end testACredentialNeverWritesTheIdentityIntoTheSession()
	public function testANcSessionWithinTheAllowListIsAccepted(): void {
		$this->loggedIn = true;
		$this->sessionUser = $this->userNamed('piet');

		$service = $this->service();
		$service->authorizeNcSession([], ['staff']);
		$this->assertNull($service->getResolvedConsumer(), 'A session authenticates a user, not a consumer.');
	}//end testANcSessionWithinTheAllowListIsAccepted()

	public function testANcSessionOutsideTheAllowListIsRefused(): void {
		$this->loggedIn = true;
		$this->sessionUser = $this->userNamed('klaas');

		$this->expectException(AuthenticationException::class);
		$this->service()->authorizeNcSession([], ['staff']);
	}//end testANcSessionOutsideTheAllowListIsRefused()

	public function testANcSessionWithoutAValidCsrfTokenIsRefused(): void {
		$this->loggedIn = true;
		$this->sessionUser = $this->userNamed('piet');
		$this->passesCsrf = false;

		$this->expectException(AuthenticationException::class);
		$this->service()->authorizeNcSession([], []);
	}//end testANcSessionWithoutAValidCsrfTokenIsRefused()

	public function testAnAnonymousCallerIsRefusedOnANcSessionEndpoint(): void {
		$this->expectException(AuthenticationException::class);
		$this->service()->authorizeNcSession([], []);
	}//end testAnAnonymousCallerIsRefusedOnANcSessionEndpoint()
}//end class
