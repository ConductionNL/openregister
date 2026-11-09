<?php

/**
 * The consumer's grant binds on every consumer-backed authorize path.
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
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Consumer\ConsumerSource;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the real AuthorizationService, TokenGrantSource, TokenGrantNarrower and
 * PermissionHandler; only Nextcloud's user, session and cache objects are doubles.
 */
class AuthorizationServiceConsumerGrantTest extends TestCase {

	private const SECRET = 'ssssssssssssssssssssssssssssssss';

	private const API_KEY = 'consumer-api-key';

	private const BLOCK = ['read' => ['leveranciers'], 'update' => ['leveranciers']];

	/**
	 * A grant for reading zaak, ten years from running out.
	 *
	 * @return array{verbs: list<string>, schemas: list<string>, expiresAt: string}
	 */
	private function grant(): array {
		return ['verbs' => ['read'], 'schemas' => ['zaak'], 'expiresAt' => date('c', strtotime('+10 years'))];
	}//end grant()

	/**
	 * The real service with the given grant source wired in.
	 *
	 * @param TokenGrantSource $grants The grant source.
	 *
	 * @return AuthorizationService
	 */
	private function service(TokenGrantSource $grants): AuthorizationService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('leverancier');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($store[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return new AuthorizationService(
			userManager: $users,
			userSession: $this->createMock(IUserSession::class),
			consumerMapper: $this->createMock(ConsumerMapper::class),
			tokenGrantSource: $grants,
			cacheFactory: $cacheFactory,
		);
	}//end service()

	/**
	 * A consumer source holding one consumer, found by issuer and by API key.
	 *
	 * @param array<string, mixed>|null $grant The consumer's stored grant, or null for none.
	 *
	 * @return ConsumerSource
	 */
	private function consumers(?array $grant): ConsumerSource {
		$configuration = ['algorithm' => 'HS256', 'publicKey' => self::SECRET, 'apiKey' => self::API_KEY];
		if ($grant !== null) {
			$configuration['grant'] = $grant;
		}

		$consumer = new ResolvedConsumer(
			source: 'openregister',
			uuid: 'c0ffee00-0000-4000-8000-000000000001',
			name: 'leverancier',
			userId: 'leverancier',
			authorizationType: 'apiKey',
			configuration: $configuration,
			record: null
		);

		return new class($consumer) implements ConsumerSource {
			public function __construct(private ResolvedConsumer $consumer) {
			}

			public function findByIssuer(string $issuer): ?ResolvedConsumer {
				return $this->consumer;
			}

			public function findByApiKey(string $apiKey): ?ResolvedConsumer {
				return $this->consumer;
			}
		};
	}//end consumers()

	/**
	 * A signed HS256 JWT for the consumer.
	 *
	 * @return string The Authorization header value.
	 */
	private function bearer(): string {
		$jws = (new JWSBuilder(new AlgorithmManager([new HS256()])))->create()
			->withPayload(json_encode(['iss' => 'leverancier', 'iat' => time()]))
			->addSignature(JWKFactory::createFromSecret(self::SECRET), ['alg' => 'HS256', 'typ' => 'JWT'])
			->build();

		return 'Bearer ' . (new CompactSerializer())->serialize($jws, 0);
	}//end bearer()

	/**
	 * Whether the handler, under the grant now bound, lets the consumer's group do $action on a `zaak`.
	 *
	 * @param TokenGrantSource $grants The grant source the authorize call bound.
	 * @param string           $action The action.
	 *
	 * @return bool
	 */
	private function permits(TokenGrantSource $grants, string $action): bool {
		$narrower = new TokenGrantNarrower();
		$handler = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$grants,
			$narrower
		);

		return $handler->hasGroupPermission(
			authorization: $narrower->narrow(
				authorization: self::BLOCK,
				grant: $grants->current(),
				schemaSlug: 'zaak',
				registerSlug: 'zaken'
			),
			groupId: 'leveranciers',
			action: $action,
			userId: 'leverancier',
			userGroup: 'leveranciers',
			objectOwner: 'leverancier'
		);
	}//end permits()

	/**
	 * An API-key consumer with a read-only grant is refused the write its user could do.
	 *
	 * @return void
	 */
	public function testAnApiKeyConsumerIsRefusedWhatItsGrantExcludes(): void {
		$grants = new TokenGrantSource();

		$this->service($grants)->authorizeApiKey(self::API_KEY, [], $this->consumers($this->grant()));

		$this->assertFalse($this->permits($grants, 'update'), 'the grant excludes update');
		$this->assertTrue($this->permits($grants, 'read'), 'the verb the grant holds still works');
	}//end testAnApiKeyConsumerIsRefusedWhatItsGrantExcludes()

	/**
	 * An API-key consumer without a grant is bound exactly as a JWT consumer without one: bound, narrowing nothing.
	 *
	 * @return void
	 */
	public function testAnApiKeyConsumerWithoutAGrantIsBoundAsJwtIs(): void {
		$viaJwt = new TokenGrantSource();
		$this->service($viaJwt)->authorizeJwt($this->bearer(), $this->consumers(null));

		$viaKey = new TokenGrantSource();
		$this->service($viaKey)->authorizeApiKey(self::API_KEY, [], $this->consumers(null));

		$this->assertTrue($viaJwt->isBound());
		$this->assertNull($viaJwt->current());
		$this->assertSame($viaJwt->isBound(), $viaKey->isBound(), 'a grantless API key binds as a grantless JWT does');
		$this->assertNull($viaKey->current(), 'and carries no ceiling');
		$this->assertTrue($this->permits($viaKey, 'update'), 'so its user keeps every right it had');
	}//end testAnApiKeyConsumerWithoutAGrantIsBoundAsJwtIs()

	/**
	 * JWT and API key bind the same grant for the same consumer, and the JWT one still narrows.
	 *
	 * @return void
	 */
	public function testJwtAndApiKeyBindTheSameGrant(): void {
		$viaJwt = new TokenGrantSource();
		$this->service($viaJwt)->authorizeJwt($this->bearer(), $this->consumers($this->grant()));

		$viaKey = new TokenGrantSource();
		$this->service($viaKey)->authorizeApiKey(self::API_KEY, [], $this->consumers($this->grant()));

		$this->assertNotNull($viaJwt->current());
		$this->assertSame('c0ffee00-0000-4000-8000-000000000001', $viaJwt->current()->tokenId);
		$this->assertFalse($this->permits($viaJwt, 'update'), 'the JWT path still narrows');
		$this->assertEquals($viaJwt->current(), $viaKey->current(), 'both paths bind the same grant');
	}//end testJwtAndApiKeyBindTheSameGrant()
}//end class
