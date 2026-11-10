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

namespace OCA\OpenRegister\Notifications\Service\Notification;

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
class RateLimiter implements RateLimiterInterface {

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
		$this->cache = $this->openCache(cacheFactory: $cacheFactory);
		$this->timeProvider = ($timeProvider ?? static fn (): int => time());

	}//end __construct()

	/**
	 * The distributed cache, or null (logged) when no backend is available.
	 *
	 * @param ICacheFactory $cacheFactory Distributed cache factory.
	 *
	 * @return ICache|null
	 */
	private function openCache(ICacheFactory $cacheFactory): ?ICache {
		try {
			return $cacheFactory->createDistributed('openregister_notification_rate_limit');
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRateLimiter] cache backend unavailable: %s', $e->getMessage())
			);
			return null;
		}//end try

	}//end openCache()

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

		$rule = $this->read(
			bucket: $this->ruleBucket(key: $this->key(ruleId: $ruleId, recipient: $recipient), perRuleOverride: $perRuleOverride),
			label: sprintf('rule="%s" recipient="%s"', $ruleId, $recipient)
		);

		return $this->consumeFromRule(rule: $rule, recipient: $recipient);
	}//end tryConsume()

	/**
	 * Decide on the rule bucket that was read; null (cache down) fails open.
	 * A refusal leaves the shared bucket untouched: it messaged nobody.
	 *
	 * @param BucketReading|null $rule      The rule bucket, or null.
	 * @param string             $recipient Recipient identifier.
	 *
	 * @return bool True when the dispatch may proceed.
	 */
	private function consumeFromRule(?BucketReading $rule, string $recipient): bool {
		if ($rule === null) {
			return true;
		}

		if ($rule->tokens < 1.0) {
			return $this->drop(reading: $rule);
		}

		return $this->consumeWithShared(rule: $rule, recipient: $recipient);
	}//end consumeFromRule()

	/**
	 * Consume from the shared per-recipient budget after the rule bucket allowed it.
	 *
	 * @param BucketReading $rule      The rule bucket, holding at least one token.
	 * @param string        $recipient Recipient identifier.
	 *
	 * @return bool True when the dispatch may proceed.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function consumeWithShared(BucketReading $rule, string $recipient): bool {
		$shared = $this->read(
			bucket: $this->sharedBucket(recipient: $recipient, now: $rule->bucket->now),
			label: $rule->label.' by the shared per-recipient budget'
		);
		if ($shared !== null && $shared->tokens < 1.0) {
			return $this->drop(reading: $shared);
		}

		$this->spend(reading: $rule);
		$this->spend(reading: $shared);
		return true;
	}//end consumeWithShared()

	/**
	 * Log a dropped dispatch and persist the bucket's refilled state, so partial
	 * earnings don't get lost on the next call.
	 *
	 * @param BucketReading $reading The bucket that had no token.
	 *
	 * @return false
	 */
	private function drop(BucketReading $reading): bool {
		$this->logger->info(
			sprintf(
				'[NotificationRateLimiter] dropped %s bucket=%d refillSeconds=%d',
				$reading->label,
				$reading->bucket->size,
				$reading->bucket->refillSeconds
			)
		);
		$this->persist(bucket: $reading->bucket, tokens: $reading->tokens);
		return false;
	}//end drop()

	/**
	 * Take one token from a bucket that was read; nothing for no bucket.
	 *
	 * @param BucketReading|null $reading The bucket, or null.
	 *
	 * @return void
	 */
	private function spend(?BucketReading $reading): void {
		if ($reading !== null) {
			$this->persist(bucket: $reading->bucket, tokens: ($reading->tokens - 1.0));
		}

	}//end spend()

	/**
	 * Read a bucket's refilled tokens, or null for no bucket or when the cache
	 * cannot answer (the limiter then fails open).
	 *
	 * @param TokenBucket|null $bucket The bucket, or null.
	 * @param string           $label  The label a drop of it is logged under.
	 *
	 * @return BucketReading|null
	 */
	private function read(?TokenBucket $bucket, string $label): ?BucketReading {
		if ($bucket === null) {
			return null;
		}

		$tokens = $this->currentTokens(bucket: $bucket);
		if ($tokens === null) {
			return null;
		}

		return new BucketReading(bucket: $bucket, tokens: $tokens, label: $label);
	}//end read()

	/**
	 * The (rule, recipient) bucket, sized from the per-rule override and read now.
	 *
	 * @param string                    $key             Cache key.
	 * @param array<string, mixed>|null $perRuleOverride Per-rule rateLimit block.
	 *
	 * @return TokenBucket
	 */
	private function ruleBucket(string $key, ?array $perRuleOverride): TokenBucket {
		$limits = $this->resolveLimits(perRuleOverride: $perRuleOverride);
		return new TokenBucket(key: $key, size: $limits[0], refillSeconds: $limits[1], now: ($this->timeProvider)());
	}//end ruleBucket()

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
	 */
	private function resolveLimits(?array $perRuleOverride): array {
		$configured = $this->configuredLimits();
		$override = ($perRuleOverride ?? []);

		return [
			($this->overrideValue(raw: ($override['bucketSize'] ?? null)) ?? $configured[0]),
			($this->overrideValue(raw: ($override['refillSecondsPerToken'] ?? null)) ?? $configured[1]),
		];

	}//end resolveLimits()

	/**
	 * The app-config (bucketSize, refillSeconds), falling back to the class
	 * defaults. A config read that throws stops reading; what was read stays.
	 *
	 * @return array{0:int,1:int}
	 */
	private function configuredLimits(): array {
		$limits = [self::DEFAULT_BUCKET_SIZE, self::DEFAULT_REFILL_SECONDS];
		try {
			$limits[0] = $this->configuredPositive(key: self::CONFIG_DEFAULT_BUCKET_SIZE, default: self::DEFAULT_BUCKET_SIZE);
			$limits[1] = $this->configuredPositive(key: self::CONFIG_DEFAULT_REFILL_SECONDS, default: self::DEFAULT_REFILL_SECONDS);
		} catch (\Throwable $e) {
			// Fall through with what was read so far.
		}//end try

		return $limits;

	}//end configuredLimits()

	/**
	 * A positive app-config integer, or the default for zero and below.
	 *
	 * @param string $key     App-config key.
	 * @param int    $default Default value.
	 *
	 * @return int
	 */
	private function configuredPositive(string $key, int $default): int {
		$configured = (int)$this->appConfig->getValueInt(self::APP_ID, $key, $default);
		if ($configured > 0) {
			return $configured;
		}

		return $default;

	}//end configuredPositive()

	/**
	 * A per-rule override value: a positive int or an all-digit string of one.
	 *
	 * @param mixed $raw The raw override value.
	 *
	 * @return int|null Null when the value does not override.
	 */
	private function overrideValue(mixed $raw): ?int {
		if (is_string($raw) === true && ctype_digit($raw) === true) {
			$raw = (int)$raw;
		}

		if (is_int($raw) === true && $raw > 0) {
			return $raw;
		}

		return null;

	}//end overrideValue()

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
		$state = json_encode(['tokens' => $tokens, 'lastRefill' => $bucket->now]);
		try {
			$this->cache?->set($bucket->key, $state, self::STATE_TTL);
		} catch (\Throwable $e) {
			// Don't escalate.
		}//end try

	}//end persist()
}//end class
