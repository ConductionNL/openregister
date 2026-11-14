<?php

/**
 * A token's own rate limit: counted per token in a one-minute window, and the
 * call over it refused by the authorisation layer, naming the limit.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Exception\TokenRateLimitExceededException;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\Consumer\ConsumerSource;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantRateLimiter;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCA\OpenRegister\Service\UserDirectory;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenGrantRateLimiter::class)]
#[CoversClass(TokenRateLimitExceededException::class)]
#[UsesClass(AuthorizationService::class)]
#[UsesClass(AuthenticationException::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantTerms::class)]
#[UsesClass(TokenGrantSource::class)]
#[UsesClass(ResolvedConsumer::class)]
#[UsesClass(UserDirectory::class)]
final class TokenGrantRateLimiterTest extends TestCase {
	/**
	 * The counters the fake memcache holds, keyed by cache key.
	 *
	 * @var array<string, int>
	 */
	private array $counters = [];

	private function cacheFactory(bool $available = true): ICacheFactory {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturnCallback(
			function (string $key): int {
				$this->counters[$key] = (($this->counters[$key] ?? 0) + 1);
				return $this->counters[$key];
			}
		);
		$cache->method('set')->willReturn(true);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}//end cacheFactory()

	private function grant(?int $rateLimit, string $tokenId = 'consumer-1'): TokenGrant {
		return new TokenGrant(
			verbs: ['read'],
			terms: new TokenGrantTerms(expiresAt: new \DateTimeImmutable('+1 year'), rateLimit: $rateLimit),
			tokenId: $tokenId
		);
	}//end grant()

	public function testCallsUpToTheLimitAreAllowedAndTheNextIsNot(): void {
		$limiter = new TokenGrantRateLimiter($this->cacheFactory());
		$now = 1_800_000_010;

		$first = $limiter->consume($this->grant(2), $now);
		$second = $limiter->consume($this->grant(2), $now);
		$third = $limiter->consume($this->grant(2), $now);

		self::assertSame(['allowed' => true, 'limit' => 2, 'remaining' => 1, 'resetAt' => 1_800_000_060], $first);
		self::assertTrue($second['allowed']);
		self::assertSame(0, $second['remaining']);
		self::assertFalse($third['allowed']);
		self::assertSame(1_800_000_060, $third['resetAt']);
	}//end testCallsUpToTheLimitAreAllowedAndTheNextIsNot()

	public function testANewMinuteStartsANewCount(): void {
		$limiter = new TokenGrantRateLimiter($this->cacheFactory());

		$limiter->consume($this->grant(1), 1_800_000_010);
		$over = $limiter->consume($this->grant(1), 1_800_000_020);
		$next = $limiter->consume($this->grant(1), 1_800_000_070);

		self::assertFalse($over['allowed']);
		self::assertTrue($next['allowed']);
	}//end testANewMinuteStartsANewCount()

	public function testEachTokenIsCountedOnItsOwn(): void {
		$limiter = new TokenGrantRateLimiter($this->cacheFactory());

		$limiter->consume($this->grant(1, 'token-a'), 1_800_000_010);
		$other = $limiter->consume($this->grant(1, 'token-b'), 1_800_000_010);

		self::assertTrue($other['allowed'], 'one runaway token must not spend another token\'s budget');
	}//end testEachTokenIsCountedOnItsOwn()

	public function testAGrantWithoutALimitOrIdOrCacheIsNotCounted(): void {
		self::assertNull((new TokenGrantRateLimiter($this->cacheFactory()))->consume($this->grant(null), 1));
		self::assertNull((new TokenGrantRateLimiter($this->cacheFactory()))->consume($this->grant(5, ''), 1));
		self::assertNull((new TokenGrantRateLimiter($this->cacheFactory(false)))->consume($this->grant(5), 1));
		self::assertSame([], $this->counters);
	}//end testAGrantWithoutALimitOrIdOrCacheIsNotCounted()

	private function service(TokenGrantSource $grants): AuthorizationService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('leverancier');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		return new AuthorizationService(
			directory: new UserDirectory(session: $this->createMock(IUserSession::class), users: $users, groups: $this->createMock(IGroupManager::class)),
			consumerMapper: $this->createMock(ConsumerMapper::class),
			tokenGrantSource: $grants,
			cacheFactory: $this->cacheFactory()
		);
	}//end service()

	private function consumers(array $grant): ConsumerSource {
		$consumer = new ResolvedConsumer(
			source: 'openregister',
			uuid: 'c0ffee00-0000-4000-8000-000000000001',
			name: 'leverancier',
			userId: 'leverancier',
			authorizationType: 'apiKey',
			configuration: ['apiKey' => 'key', 'grant' => $grant],
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

	public function testTheCallOverTheGrantsLimitIsRefusedNamingTheLimit(): void {
		$service = $this->service(new TokenGrantSource());
		$consumers = $this->consumers(['verbs' => ['read'], 'expiresAt' => date('c', strtotime('+1 year')), 'rateLimit' => 2]);

		$service->authorizeApiKey('key', [], $consumers);
		$service->authorizeApiKey('key', [], $consumers);

		try {
			$service->authorizeApiKey('key', [], $consumers);
			self::fail('the third call in the minute must be refused');
		} catch (TokenRateLimitExceededException $refused) {
			self::assertInstanceOf(AuthenticationException::class, $refused, 'existing callers refuse it as a failed credential');
			self::assertSame(429, $refused->getStatusCode());
			self::assertStringContainsString('2 calls per 60 seconds', $refused->getMessage());
			self::assertSame(2, $refused->getDetails()['limit']);
			self::assertSame('token-rate-limit', $refused->getDetails()['reason']);
			self::assertGreaterThanOrEqual(0, $refused->getDetails()['retryAfter']);
			self::assertLessThanOrEqual(60, $refused->getDetails()['retryAfter']);
		}

		self::assertNull($service->getResolvedConsumer(), 'the refused call never acts as the consumer');
	}//end testTheCallOverTheGrantsLimitIsRefusedNamingTheLimit()

	public function testAGrantWithoutALimitIsNeverRefusedForRate(): void {
		$service = $this->service(new TokenGrantSource());
		$consumers = $this->consumers(['verbs' => ['read'], 'expiresAt' => date('c', strtotime('+1 year'))]);

		for ($call = 0; $call < 5; $call++) {
			$service->authorizeApiKey('key', [], $consumers);
		}

		self::assertNotNull($service->getResolvedConsumer());
		self::assertSame([], $this->counters, 'no counter is kept for a grant without a limit');
	}//end testAGrantWithoutALimitIsNeverRefusedForRate()
}//end class
