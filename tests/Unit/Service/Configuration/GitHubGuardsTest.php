<?php

/**
 * GitHubGuards Unit Tests.
 *
 * Covers the policy guards (admin opt-out, repo allowlist, per-user GET rate limit) and the
 * `runGuards` pipeline runner. Validators (validateRepoFormat etc.) are tested separately in
 * GitHubRequestValidatorTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\GitHubGuards;
use OCA\OpenRegister\Service\Configuration\RateLimiterService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `GitHubGuards`.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubGuards
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-11
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-19
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-21
 */
class GitHubGuardsTest extends TestCase
{
    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-21
     */
    public function testFeatureFlagEnabledByDefault(): void
    {
        $guards = $this->buildGuards(repoConfig: 'ConductionNL/openregister', flagEnabled: true);
        $this->assertNull($guards->enforceFeatureFlag());
    }//end testFeatureFlagEnabledByDefault()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-21
     */
    public function testFeatureFlagDisabledReturns403(): void
    {
        $guards   = $this->buildGuards(repoConfig: 'ConductionNL/openregister', flagEnabled: false);
        $response = $guards->enforceFeatureFlag();
        $this->assertEquals(403, $response->getStatus());
        $this->assertEquals('feature_disabled', $this->errorCode(response: $response));
    }//end testFeatureFlagDisabledReturns403()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
     */
    public function testRepoAllowlistUnsetReturnsGracefulOnGet(): void
    {
        $guards   = $this->buildGuards(repoConfig: '', flagEnabled: true);
        $response = $guards->enforceRepoAllowlist(repo: 'ConductionNL/openregister', isRead: true);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame([], $data['items'] ?? null);
        $this->assertSame('github_repo_not_configured', $data['hint'] ?? null);
    }//end testRepoAllowlistUnsetReturnsGracefulOnGet()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
     */
    public function testRepoAllowlistUnsetReturns503OnPost(): void
    {
        $guards   = $this->buildGuards(repoConfig: '', flagEnabled: true);
        $response = $guards->enforceRepoAllowlist(repo: 'ConductionNL/openregister', isRead: false);

        $this->assertEquals(503, $response->getStatus());
        $this->assertEquals('github_repo_not_configured', $this->errorCode(response: $response));
    }//end testRepoAllowlistUnsetReturns503OnPost()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
     */
    public function testRepoMismatchReturns403(): void
    {
        $guards   = $this->buildGuards(repoConfig: 'ConductionNL/openregister', flagEnabled: true);
        $response = $guards->enforceRepoAllowlist(repo: 'torvalds/linux', isRead: false);

        $this->assertEquals(403, $response->getStatus());
        $this->assertEquals('repo_not_allowed', $this->errorCode(response: $response));
    }//end testRepoMismatchReturns403()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
     */
    public function testRepoMatchPasses(): void
    {
        $guards = $this->buildGuards(repoConfig: 'ConductionNL/openregister', flagEnabled: true);
        $this->assertNull($guards->enforceRepoAllowlist(repo: 'ConductionNL/openregister', isRead: true));
    }//end testRepoMatchPasses()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-19
     */
    public function testGetRateLimitEleventhMissReturns429(): void
    {
        // 10 distinct cache-miss keys (within window) → all pass.
        // The 11th distinct key → 429.
        $factory = $this->buildCacheFactory(cache: $this->buildArrayCache(state: new \ArrayObject()), operational: true);
        $guards  = new GitHubGuards(
            appConfig: $this->buildAppConfig(repoConfig: 'ConductionNL/openregister', flagEnabled: true),
            userSession: $this->buildUserSession(uid: 'alice'),
            rateLimiter: new RateLimiterService(cacheFactory: $factory)
        );

        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($guards->enforceGetRateLimit(cacheKey: 'k-'.$i), 'miss '.$i.' should pass');
        }

        $response = $guards->enforceGetRateLimit(cacheKey: 'k-11');
        $this->assertEquals(429, $response->getStatus());
        $this->assertEquals('user_rate_limited', $this->errorCode(response: $response));
    }//end testGetRateLimitEleventhMissReturns429()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
     */
    public function testGetRateLimitFailsClosedWhenNoCacheBackend(): void
    {
        $factory = $this->buildCacheFactory(cache: $this->buildArrayCache(state: new \ArrayObject()), operational: false);
        $guards  = new GitHubGuards(
            appConfig: $this->buildAppConfig(repoConfig: 'ConductionNL/openregister', flagEnabled: true),
            userSession: $this->buildUserSession(uid: 'alice'),
            rateLimiter: new RateLimiterService(cacheFactory: $factory)
        );

        $response = $guards->enforceGetRateLimit(cacheKey: 'k-1');
        $this->assertEquals(503, $response->getStatus());
        $this->assertEquals('rate_limiter_unavailable', $this->errorCode(response: $response));
    }//end testGetRateLimitFailsClosedWhenNoCacheBackend()

    /**
     * @return void
     */
    public function testRunGuardsShortCircuitsOnFirstFailure(): void
    {
        $guards = $this->buildGuards(repoConfig: 'ConductionNL/openregister', flagEnabled: true);
        $hits   = 0;

        $response = $guards->runGuards(
            [
                function () use (&$hits) {
                    $hits++;
                    return null;
                },
                function () use (&$hits) {
                    $hits++;
                    return new JSONResponse(['error' => 'short_circuit'], 400);
                },
                function () use (&$hits) {
                    $hits++;
                    return null;
                },
            ]
        );

        $this->assertEquals('short_circuit', $this->errorCode(response: $response));
        // The third guard MUST NOT have been called.
        $this->assertEquals(2, $hits, 'runGuards must short-circuit on first failing response');
    }//end testRunGuardsShortCircuitsOnFirstFailure()

    /**
     * Build a GitHubGuards with mocked deps. The user is "alice" by default.
     *
     * @param string $repoConfig  Value returned by IAppConfig::getValueString for github_repo.
     * @param bool   $flagEnabled Value returned by IAppConfig::getValueBool for features_roadmap_enabled.
     *
     * @return GitHubGuards
     */
    private function buildGuards(string $repoConfig, bool $flagEnabled): GitHubGuards
    {
        $factory = $this->buildCacheFactory(cache: $this->buildArrayCache(state: new \ArrayObject()), operational: true);
        return new GitHubGuards(
            appConfig: $this->buildAppConfig(repoConfig: $repoConfig, flagEnabled: $flagEnabled),
            userSession: $this->buildUserSession(uid: 'alice'),
            rateLimiter: new RateLimiterService(cacheFactory: $factory)
        );
    }//end buildGuards()

    /**
     * @param string $repoConfig
     * @param bool   $flagEnabled
     *
     * @return IAppConfig
     */
    private function buildAppConfig(string $repoConfig, bool $flagEnabled): IAppConfig
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') use ($repoConfig): string {
                if ($key === 'github_repo') {
                    return $repoConfig;
                }

                return $default;
            }
        );
        $appConfig->method('getValueBool')->willReturnCallback(
            function (string $app, string $key, bool $default=false) use ($flagEnabled): bool {
                if ($key === 'features_roadmap_enabled') {
                    return $flagEnabled;
                }

                return $default;
            }
        );
        return $appConfig;
    }//end buildAppConfig()

    /**
     * @param string $uid
     *
     * @return IUserSession
     */
    private function buildUserSession(string $uid): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);
        return $userSession;
    }//end buildUserSession()

    /**
     * Build a tiny in-memory ICache backed by the supplied ArrayObject.
     *
     * @param \ArrayObject $state Shared state for get/set/remove.
     *
     * @return ICache
     */
    private function buildArrayCache(\ArrayObject $state): ICache
    {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            function (string $key) use ($state) {
                return $state->offsetExists($key) ? $state->offsetGet($key) : null;
            }
        );
        $cache->method('set')->willReturnCallback(
            function (string $key, $value, int $ttl=0) use ($state): bool {
                $state->offsetSet($key, $value);
                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(
            function (string $key) use ($state): bool {
                if ($state->offsetExists($key)) {
                    $state->offsetUnset($key);
                }

                return true;
            }
        );
        return $cache;
    }//end buildArrayCache()

    /**
     * @param ICache $cache       Cache instance returned by createDistributed().
     * @param bool   $operational Whether a cache backend is "available" (drives isAvailable /
     *                            isLocalCacheAvailable so RateLimiterService::isOperational()
     *                            returns the desired value).
     *
     * @return ICacheFactory
     */
    private function buildCacheFactory(ICache $cache, bool $operational): ICacheFactory
    {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);
        $factory->method('isAvailable')->willReturn($operational);
        $factory->method('isLocalCacheAvailable')->willReturn($operational);
        return $factory;
    }//end buildCacheFactory()

    /**
     * Extract the structured `error` field from a JSONResponse body.
     *
     * @param JSONResponse|null $response
     *
     * @return string
     */
    private function errorCode(?JSONResponse $response): string
    {
        if ($response === null) {
            return '';
        }

        $data = $response->getData();
        if (is_array($data) === false) {
            return '';
        }

        return (string) ($data['error'] ?? '');
    }//end errorCode()
}//end class
