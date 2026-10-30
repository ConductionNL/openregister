<?php

/**
 * OpenRegister Notification RateLimiter
 *
 * Token-bucket rate limiter for notification dispatches, keyed on
 * (rule, recipient). Backed by the distributed cache (APCu in dev,
 * Redis in cluster). Per-bucket state is a `[tokens, lastRefill]`
 * tuple; tokens refill at a configurable rate up to the bucket
 * size. When a token isn't available the dispatch is dropped and
 * logged at info level — operators who care can grep for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Per-(rule, recipient) token-bucket rate limiter.
 *
 * Defaults: bucket size 10, refill 1 token per 60s. Both can be
 * overridden globally via app-config and per-rule via the
 * `rateLimit` block on the notification spec. The whole limiter
 * can be killed via `notification_rate_limit_enabled = false`.
 */
class RateLimiter {

	/**
	 * Cache TTL for bucket state (24h). Stale entries get evicted
	 * naturally; we never need to keep history past that.
	 */
	public const STATE_TTL = 86400;

	public const APP_ID = 'openregister';

	public const CONFIG_ENABLED = 'notification_rate_limit_enabled';

	public const CONFIG_DEFAULT_BUCKET_SIZE = 'notification_rate_limit_default_bucket_size';

	public const CONFIG_DEFAULT_REFILL_SECONDS = 'notification_rate_limit_default_refill_seconds';

	public const DEFAULT_BUCKET_SIZE = 10;

	public const DEFAULT_REFILL_SECONDS = 60;

	/**
	 * Distributed cache used to keep bucket state across requests
	 * and (with Redis) across nodes. Null when no cache backend is
	 * available — in that case the limiter fails open and never
	 * drops dispatches.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Time provider — callable returning a unix timestamp in
	 * seconds. Constructor-injectable so tests can advance time
	 * without sleep().
	 *
	 * @var callable():int
	 */
	private $timeProvider;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Distributed cache factory.
	 * @param IAppConfig $appConfig App-config reader for kill switch + defaults.
	 * @param LoggerInterface $logger Logger for dropped-dispatch info events.
	 * @param callable|null $timeProvider Optional time source (defaults to time()).
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		?callable $timeProvider = null,
	) {
		try {
			$this->cache = $cacheFactory->createDistributed('openregister_notification_rate_limit');
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRateLimiter] cache backend unavailable: %s', $e->getMessage())
			);
			$this->cache = null;
		}//end try

		$this->timeProvider = ($timeProvider ?? static fn (): int => time());

	}//end __construct()

	/**
	 * Try to consume one token for the given (rule, recipient).
	 *
	 * Returns true when the dispatch may proceed. Returns false when
	 * the bucket is empty — the caller MUST then skip the dispatch.
	 * Logs an info-level entry on drop so operators can grep
	 * "[NotificationRateLimiter] dropped".
	 *
	 * Fails open: when the limiter is disabled, the cache is
	 * unavailable, or a state read fails — return true so a broken
	 * limiter never blocks legitimate notifications.
	 *
	 * @param string $ruleId Stable rule identifier (annotation key).
	 * @param string $recipient Recipient ID (uid, group id, webhook URL hash, etc.).
	 * @param array<string, mixed>|null $perRuleOverride Optional `rateLimit` block from the rule spec.
	 *
	 * @return bool True when the dispatch may proceed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-8
	 */
	public function tryConsume(string $ruleId, string $recipient, ?array $perRuleOverride = null): bool {
		if ($this->bypasses(ruleId: $ruleId, recipient: $recipient) === true) {
			return true;
		}

		[$bucketSize, $refillSeconds] = $this->resolveLimits(perRuleOverride: $perRuleOverride);
		$ruleBucket = new TokenBucket(
			key: $this->key(ruleId: $ruleId, recipient: $recipient),
			size: $bucketSize,
			refillSeconds: $refillSeconds,
			now: ($this->timeProvider)()
		);

		$ruleTokens = $this->currentTokens(bucket: $ruleBucket);
		if ($ruleTokens === null) {
			return true;
		}

		if ($ruleTokens < 1.0) {
			$this->logger->info(
				sprintf(
					'[NotificationRateLimiter] dropped rule="%s" recipient="%s" bucket=%d refillSeconds=%d',
					$ruleId,
					$recipient,
					$bucketSize,
					$refillSeconds
				)
			);
			// Persist the new lastRefill so partial earnings don't
			// get lost on the next call. The shared bucket is untouched:
			// a refused dispatch messaged nobody.
			$this->persist(bucket: $ruleBucket, tokens: $ruleTokens);
			return false;
		}

		$sharedBucket = $this->sharedBucket(recipient: $recipient, now: $ruleBucket->now);
		$sharedTokens = null;
		if ($sharedBucket !== null) {
			$sharedTokens = $this->currentTokens(bucket: $sharedBucket);
		}

		if ($sharedTokens !== null && $sharedTokens < 1.0) {
			$this->logger->info(
				sprintf(
					'[NotificationRateLimiter] dropped rule="%s" recipient="%s" by the shared per-recipient budget bucket=%d refillSeconds=%d',
					$ruleId,
					$recipient,
					$sharedBucket->size,
					$sharedBucket->refillSeconds
				)
			);
			$this->persist(bucket: $sharedBucket, tokens: $sharedTokens);
			return false;
		}

		$this->persist(bucket: $ruleBucket, tokens: ($ruleTokens - 1.0));
		if ($sharedTokens !== null) {
			$this->persist(bucket: $sharedBucket, tokens: ($sharedTokens - 1.0));
		}

		return true;
	}//end tryConsume()

	/**
	 * Whether a dispatch passes without touching a bucket.
	 *
	 * It does when the limiter is off or has no cache, and when the rule or
	 * recipient is empty: a rule without an id can't be rate-limited in a
	 * stable way, so the limiter fails open rather than silently grouping
	 * unrelated rules under the empty-string key.
	 *
	 * @param string $ruleId    Rule identifier.
	 * @param string $recipient Recipient identifier.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function bypasses(string $ruleId, string $recipient): bool {
		return $this->isEnabled() === false || $this->cache === null || $ruleId === '' || $recipient === '';
	}//end bypasses()

	/**
	 * The shared per-recipient budget, or null for a pseudo-recipient.
	 *
	 * Bucket keys are caller-agnostic (the key is the recipient, nothing
	 * else), so a declarative rule and a flow send node draw from ONE budget
	 * by construction: the abuse being bounded is "how much this instance
	 * messages this person", not "per subsystem" or "per rule". Sized from the
	 * app-config defaults only; a single rule's generous per-rule override
	 * must not widen every caller's ceiling. Pseudo-recipients (broadcast
	 * channel keys such as `__webhook__`) are not people and stay outside it.
	 *
	 * @param string $recipient Recipient identifier.
	 * @param int    $now       Current unix timestamp.
	 *
	 * @return TokenBucket|null
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function sharedBucket(string $recipient, int $now): ?TokenBucket {
		[$bucketSize, $refillSeconds] = $this->resolveLimits(perRuleOverride: null);
		if (str_starts_with($recipient, '__') === true) {
			return null;
		}

		return new TokenBucket(
			key: $this->sharedKey(recipient: $recipient),
			size: $bucketSize,
			refillSeconds: $refillSeconds,
			now: $now
		);
	}//end sharedBucket()

	/**
	 * The refilled token count for a bucket, or null when the cache cannot
	 * answer (the limiter then fails open, as everywhere else).
	 *
	 * @param TokenBucket $bucket The bucket.
	 *
	 * @return float|null The available tokens, or null to fail open.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function currentTokens(TokenBucket $bucket): ?float {
		try {
			$state = $this->cache?->get($bucket->key);
		} catch (\Throwable $e) {
			return null;
		}//end try

		return $bucket->tokensFrom(state: $state);
	}//end currentTokens()

	/**
	 * The shared per-recipient budget's cache key. Deliberately blind to the
	 * rule and to the caller: every subsystem that messages this recipient
	 * lands on the same bucket.
	 *
	 * @param string $recipient Recipient identifier.
	 *
	 * @return string Cache key.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function sharedKey(string $recipient): string {
		return 'notification:rate:shared:' . sha1($recipient);
	}//end sharedKey()

	/**
	 * Whether the limiter is enabled. Defaults to ON.
	 *
	 * @return bool True when the limiter should run.
	 */
	private function isEnabled(): bool {
		try {
			$value = $this->appConfig->getValueString(self::APP_ID, self::CONFIG_ENABLED, 'true');
		} catch (\Throwable $e) {
			return true;
		}//end try

		return ($value !== 'false' && $value !== '0');
	}//end isEnabled()

	/**
	 * Resolve effective (bucketSize, refillSeconds).
	 *
	 * Order: per-rule override > app-config default > class default.
	 *
	 * @param array<string, mixed>|null $perRuleOverride Per-rule rateLimit block.
	 *
	 * @return array{0:int,1:int} Tuple of (bucketSize, refillSeconds).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function resolveLimits(?array $perRuleOverride): array {
		$bucketSize = self::DEFAULT_BUCKET_SIZE;
		$refillSeconds = self::DEFAULT_REFILL_SECONDS;

		try {
			$configuredBucket = (int)$this->appConfig->getValueInt(
				self::APP_ID,
				self::CONFIG_DEFAULT_BUCKET_SIZE,
				self::DEFAULT_BUCKET_SIZE
			);
			if ($configuredBucket > 0) {
				$bucketSize = $configuredBucket;
			}

			$configuredRefill = (int)$this->appConfig->getValueInt(
				self::APP_ID,
				self::CONFIG_DEFAULT_REFILL_SECONDS,
				self::DEFAULT_REFILL_SECONDS
			);
			if ($configuredRefill > 0) {
				$refillSeconds = $configuredRefill;
			}
		} catch (\Throwable $e) {
			// Fall through with class defaults.
		}//end try

		if (is_array($perRuleOverride) === true) {
			$rawBucket = ($perRuleOverride['bucketSize'] ?? null);
			if (is_int($rawBucket) === true && $rawBucket > 0) {
				$bucketSize = $rawBucket;
			} elseif (is_string($rawBucket) === true && ctype_digit($rawBucket) === true && (int)$rawBucket > 0) {
				$bucketSize = (int)$rawBucket;
			}

			$rawRefill = ($perRuleOverride['refillSecondsPerToken'] ?? null);
			if (is_int($rawRefill) === true && $rawRefill > 0) {
				$refillSeconds = $rawRefill;
			} elseif (is_string($rawRefill) === true && ctype_digit($rawRefill) === true && (int)$rawRefill > 0) {
				$refillSeconds = (int)$rawRefill;
			}
		}

		return [
			$bucketSize,
			$refillSeconds,
		];

	}//end resolveLimits()

	/**
	 * Build the cache key. Hashes rule + recipient so colons or
	 * special chars in either don't collide with the cache key
	 * separator.
	 *
	 * @param string $ruleId Rule identifier.
	 * @param string $recipient Recipient identifier.
	 *
	 * @return string Cache key.
	 */
	private function key(string $ruleId, string $recipient): string {
		return 'notification:rate:' . sha1($ruleId . '|' . $recipient);
	}//end key()

	/**
	 * Persist bucket state, refilled at the bucket's moment. Best-effort;
	 * cache write failures are swallowed so a transient cache hiccup never
	 * throws into the dispatch path.
	 *
	 * @param TokenBucket $bucket The bucket.
	 * @param float       $tokens Current token count.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function persist(TokenBucket $bucket, float $tokens): void {
		if ($this->cache === null) {
			return;
		}

		try {
			$this->cache->set(
				$bucket->key,
				json_encode(
					[
						'tokens' => $tokens,
						'lastRefill' => $bucket->now,
					]
				),
				self::STATE_TTL
			);
		} catch (\Throwable $e) {
			// Don't escalate.
		}//end try

	}//end persist()
}//end class
