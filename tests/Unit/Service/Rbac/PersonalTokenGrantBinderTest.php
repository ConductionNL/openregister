<?php

/**
 * Binding a personal token's grant: an app-password request binds its
 * token's grant and is counted against its limit; an interactive request, a
 * token without a grant and a request a Consumer bound are left alone; a token
 * that cannot be resolved permits nothing.
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
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Exception\TokenRateLimitExceededException;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantBinder;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantRateLimiter;
use OCA\OpenRegister\Service\Rbac\TokenGrantReach;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use OCP\ISession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PersonalTokenGrantBinder::class)]
#[UsesClass(PersonalTokenGrantStore::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantReach::class)]
#[UsesClass(TokenGrantTerms::class)]
#[UsesClass(TokenGrantSource::class)]
#[UsesClass(TokenGrantRateLimiter::class)]
#[UsesClass(TokenRateLimitExceededException::class)]
#[UsesClass(AuthenticationException::class)]
final class PersonalTokenGrantBinderTest extends TestCase {
	/**
	 * Stored user values, keyed by "uid/key".
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * Rate-limit counters, keyed by cache key.
	 *
	 * @var array<string, int>
	 */
	private array $counters = [];

	private TokenGrantSource $source;

	protected function setUp(): void {
		$this->source = new TokenGrantSource();
	}//end setUp()

	private function binder(?string $password, ?ITokenProvider $provider = null): PersonalTokenGrantBinder {
		$session = $this->createMock(ISession::class);
		$session->method('get')->with('app_password')->willReturn($password);

		if ($provider === null) {
			$token = $this->createMock(IToken::class);
			$token->method('getId')->willReturn(42);
			$token->method('getUID')->willReturn('alice');
			$provider = $this->createMock(ITokenProvider::class);
			$provider->method('getToken')->with('secret-app-password')->willReturn($token);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = ''): string => ($this->values["$uid/$key"] ?? $default)
		);

		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturnCallback(
			function (string $key): int {
				$this->counters[$key] = (($this->counters[$key] ?? 0) + 1);
				return $this->counters[$key];
			}
		);
		$cache->method('set')->willReturn(true);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		return new PersonalTokenGrantBinder($session, $provider, new PersonalTokenGrantStore($config), $this->source, $factory);
	}//end binder()

	private function storeGrant(string $uid, string $tokenId, array $grant): void {
		$this->values["$uid/token_grant_$tokenId"] = (string) json_encode(['grant' => $grant]);
	}//end storeGrant()

	public function testAnInteractiveRequestBindsNothing(): void {
		$this->storeGrant('alice', '42', ['verbs' => ['read']]);
		$binder = $this->binder(null);

		$this->assertFalse($binder->isTokenRequest());
		$this->assertNull($binder->bind(1000));
		$this->assertFalse($this->source->isBound());
	}//end testAnInteractiveRequestBindsNothing()

	public function testATokenWithoutAGrantKeepsItsOwnersRights(): void {
		$binder = $this->binder('secret-app-password');

		$this->assertTrue($binder->isTokenRequest());
		$this->assertNull($binder->bind(1000));
		$this->assertFalse($this->source->isBound());
	}//end testATokenWithoutAGrantKeepsItsOwnersRights()

	public function testATokensGrantIsBoundAndNamesTheToken(): void {
		$this->storeGrant('alice', '42', ['verbs' => ['read'], 'schemas' => ['case']]);

		$grant = $this->binder('secret-app-password')->bind(1000);

		$this->assertTrue($this->source->isBound());
		$this->assertSame($grant, $this->source->current());
		$this->assertSame('app-password:42', $grant->tokenId);
		$this->assertSame(['case'], $grant->schemas);
		$this->assertFalse($grant->permits('update'));
	}//end testATokensGrantIsBoundAndNamesTheToken()

	public function testAGrantIsReadOnlyFromTheTokenOwnersStore(): void {
		$this->storeGrant('bob', '42', ['verbs' => ['read']]);

		$this->assertNull($this->binder('secret-app-password')->bind(1000));
		$this->assertFalse($this->source->isBound());
	}//end testAGrantIsReadOnlyFromTheTokenOwnersStore()

	public function testAConsumerBoundEarlierIsNotReplaced(): void {
		$consumerGrant = new TokenGrant(verbs: ['read'], tokenId: 'consumer-uuid');
		$this->source->bind($consumerGrant);
		$this->storeGrant('alice', '42', ['verbs' => ['read', 'update']]);

		$this->assertNull($this->binder('secret-app-password')->bind(1000));
		$this->assertSame($consumerGrant, $this->source->current());
	}//end testAConsumerBoundEarlierIsNotReplaced()

	public function testATokenThatCannotBeResolvedPermitsNothing(): void {
		$provider = $this->createMock(ITokenProvider::class);
		$provider->method('getToken')->willThrowException(new RuntimeException('token table unavailable'));

		$grant = $this->binder('secret-app-password', $provider)->bind(1000);

		$this->assertTrue($this->source->isBound());
		$this->assertTrue($grant->isEmpty());
		$this->assertFalse($grant->permits('read'));
	}//end testATokenThatCannotBeResolvedPermitsNothing()

	public function testTheCallOverTheGrantsLimitIsRefusedNamingTheLimit(): void {
		$this->storeGrant('alice', '42', ['verbs' => ['read'], 'rateLimit' => 2]);
		// Each call is its own request, with its own grant source.
		foreach ([1200, 1201] as $now) {
			$this->source = new TokenGrantSource();
			$this->assertNotNull($this->binder('secret-app-password')->bind($now));
		}

		$this->source = new TokenGrantSource();
		try {
			$this->binder('secret-app-password')->bind(1202);
			$this->fail('The third call in the window was not refused');
		} catch (TokenRateLimitExceededException $refused) {
			$this->assertSame(429, $refused->getStatusCode());
			$this->assertSame(2, $refused->getDetails()['limit']);
			$this->assertSame(58, $refused->getDetails()['retryAfter']);
		}
	}//end testTheCallOverTheGrantsLimitIsRefusedNamingTheLimit()
}//end class
