<?php

/**
 * OpenRegister Notification Coalescer.
 *
 * Per-(rule, recipient) debounce service that coalesces a burst of
 * notifications into a single dispatch. The first event in a window
 * fires; subsequent events within the same window are silently
 * absorbed (a counter is bumped so the eventual dispatch can render
 * "5 changes in the last minute" if the channel cares).
 *
 * Closes the `notificatie-engine` spec's
 * "Notification grouping MUST reduce noise for related events"
 * requirement. Wired into `AnnotationNotificationDispatcher` as an
 * optional dependency — when absent or when no rule declares
 * `coalesce`, dispatch behaves as before.
 *
 * Backed by the same distributed cache used by `RateLimiter` so the
 * window state survives across requests + (with Redis) across
 * cluster nodes.
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
 * Per-(rule, recipient) debounce coalescer.
 *
 * Schema authors enable coalescing via
 * `coalesce: {windowSeconds: <int>, maxEvents?: <int>}` on the
 * notification spec. When configured, the first event opens the
 * window + fires the dispatch; subsequent events while the window
 * is open are silenced and only bump the counter. After the window
 * elapses (or `maxEvents` is reached), the next event opens a fresh
 * window.
 *
 * The whole coalescer can be killed via app-config
 * `notification_coalesce_enabled = false`.
 */
class NotificationCoalescer {

	public const APP_ID = 'openregister';

	public const CONFIG_ENABLED = 'notification_coalesce_enabled';

	public const STATE_TTL = 86400;

	/**
	 * Distributed cache used to keep window state across requests.
	 *
	 * Null when no cache backend is available — in that case the
	 * coalescer fails open and never silences a dispatch.
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
	 * @param IAppConfig $appConfig App-config reader for kill switch.
	 * @param LoggerInterface $logger Logger for silenced-dispatch info events.
	 * @param callable|null $timeProvider Optional time source (defaults to time()).
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		?callable $timeProvider = null,
	) {
		try {
			$this->cache = $cacheFactory->createDistributed('openregister_notification_coalesce');
		} catch (\Throwable $e) {
			// The cache stays null, so the coalescer fails open.
			$this->logger->warning(sprintf('[NotificationCoalescer] cache backend unavailable: %s', $e->getMessage()));
		}//end try

		$this->timeProvider = ($timeProvider ?? static fn (): int => time());

	}//end __construct()

	/**
	 * Decide whether the dispatch may proceed.
	 *
	 * Returns true when this is the first event in the window (the
	 * dispatch fires + the window opens). Returns false when the
	 * dispatch should be silenced (the window is open + the counter
	 * has been bumped).
	 *
	 * Fails open: when the coalescer is disabled, the cache is
	 * unavailable, no rule-level config is provided, or a state read
	 * fails — return true so a broken coalescer never silences
	 * legitimate notifications.
	 *
	 * @param string $ruleId Stable rule identifier (annotation key).
	 * @param string $recipient Recipient ID (uid, `__webhook__`, etc.).
	 * @param array<string, mixed>|null $perRuleOverride Optional `coalesce` block from the rule spec.
	 *
	 * @return bool True when the dispatch may proceed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-9
	 */
	public function shouldDispatch(string $ruleId, string $recipient, ?array $perRuleOverride): bool {
		// No rule-level config = no coalescing for this rule; empty keys
		// would lump unrelated rules together.
		if ($this->isCoalescing(perRuleOverride: $perRuleOverride) === false || $ruleId === '' || $recipient === '') {
			return true;
		}

		$rule = new CoalesceRule(override: $perRuleOverride);
		if ($rule->windowSeconds <= 0) {
			return true;
		}

		return $this->advance(bucket: new CoalesceBucket(ruleId: $ruleId, recipient: $recipient), rule: $rule);
	}//end shouldDispatch()

	/**
	 * Whether coalescing applies at all: a rule-level block, the kill switch on, a cache present.
	 *
	 * @param array<string, mixed>|null $perRuleOverride Optional `coalesce` block from the rule spec.
	 *
	 * @return bool
	 */
	private function isCoalescing(?array $perRuleOverride): bool {
		return is_array($perRuleOverride) === true && $this->isEnabled() === true && $this->cache !== null;
	}//end isCoalescing()

	/**
	 * Count one event in its bucket's window and say whether it dispatches.
	 *
	 * A closed (or never opened) window, or a burst reaching `maxEvents`,
	 * fires and opens a fresh window; inside the window the event is
	 * silenced and only bumps the counter.
	 *
	 * @param CoalesceBucket $bucket The (rule, recipient) bucket.
	 * @param CoalesceRule   $rule   The rule's coalesce settings.
	 *
	 * @return bool True when the dispatch may proceed.
	 */
	private function advance(CoalesceBucket $bucket, CoalesceRule $rule): bool {
		$now   = ($this->timeProvider)();
		$state = $this->readState(key: $bucket->key());
		if ($state === null) {
			return true;
		}

		if ($rule->startsNewWindow(state: $state, now: $now) === true) {
			$this->persist(key: $bucket->key(), state: ['count' => 1, 'opened' => $now]);
			return true;
		}

		$next = ['count' => ($state['count'] + 1), 'opened' => $state['opened']];
		$this->persist(key: $bucket->key(), state: $next);
		$this->logger->info($bucket->silencedMessage(count: $next['count'], windowSeconds: $rule->windowSeconds));
		return false;
	}//end advance()

	/**
	 * Inspect the current coalesce state for a (rule, recipient).
	 *
	 * Mostly useful for tests + admin UIs that want to render
	 * "12 events suppressed in this window".
	 *
	 * @param string $ruleId Rule identifier.
	 * @param string $recipient Recipient identifier.
	 *
	 * @return array{count:int,opened:int}|null Null when no state exists.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-9
	 */
	public function inspect(string $ruleId, string $recipient): ?array {
		if ($this->cache === null || $ruleId === '' || $recipient === '') {
			return null;
		}

		try {
			$state = $this->cache->get((new CoalesceBucket(ruleId: $ruleId, recipient: $recipient))->key());
		} catch (\Throwable $e) {
			return null;
		}//end try

		return $this->decodeState(state: $state);
	}//end inspect()

	/**
	 * The stored state of a bucket; a fresh state when absent or malformed, null when the read fails.
	 *
	 * @param string $key Cache key.
	 *
	 * @return array{count:int,opened:int}|null
	 */
	private function readState(string $key): ?array {
		try {
			$state = $this->cache?->get($key);
		} catch (\Throwable $e) {
			return null;
		}//end try

		return ($this->decodeState(state: $state) ?? ['count' => 0, 'opened' => 0]);
	}//end readState()

	/**
	 * Decode a stored state, null when it is not a JSON object with `count` and `opened`.
	 *
	 * @param mixed $state The raw cache value.
	 *
	 * @return array{count:int,opened:int}|null
	 */
	private function decodeState(mixed $state): ?array {
		if (is_string($state) === false) {
			return null;
		}

		$decoded = json_decode($state, true);
		if (is_array($decoded) === false || isset($decoded['count'], $decoded['opened']) === false) {
			return null;
		}

		return ['count' => (int)$decoded['count'], 'opened' => (int)$decoded['opened']];
	}//end decodeState()

	/**
	 * Whether the coalescer is enabled. Defaults to ON.
	 *
	 * @return bool True when the coalescer should run.
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
	 * Persist the window state.
	 *
	 * @param string                      $key   Cache key.
	 * @param array{count:int,opened:int} $state Event count in the current window and the unix timestamp it opened.
	 *
	 * @return void
	 */
	private function persist(string $key, array $state): void {
		if ($this->cache === null) {
			return;
		}

		try {
			$this->cache->set($key, json_encode(['count' => $state['count'], 'opened' => $state['opened']]), self::STATE_TTL);
		} catch (\Throwable $e) {
			// Persistence failure is best-effort — log + move on.
			$this->logger->warning(
				sprintf('[NotificationCoalescer] persist failed: %s', $e->getMessage())
			);
		}//end try

	}//end persist()
}//end class
