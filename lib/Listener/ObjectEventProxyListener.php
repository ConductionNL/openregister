<?php

/**
 * Shared proxy for filtered object-event subscriptions.
 *
 * One instance of this listener stands in for every subscription declared via
 * {@see \OCA\OpenRegister\Event\ObjectEventSubscription::subscribe()}. On
 * dispatch it resolves the written object's register and schema once and then
 * invokes only the subscriptions that declared an interest in them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Invokes only the subscriptions that declared an interest in the written
 * object's register and schema.
 *
 * Dispatcher-compatibility notes, because a proxy that quietly changes
 * dispatch semantics is worse than the cost it saves:
 *
 * - **Resolution.** Listeners are resolved from the server container, which is
 *   byte-for-byte what Nextcloud already does: every listener registered via
 *   `IRegistrationContext` ends up in `ServiceEventListener` holding the
 *   dispatcher's own container, never the registering app's.
 * - **Caching.** A resolved listener is memoised for the process, matching
 *   `ServiceEventListener::__invoke()`, so construction happens once.
 * - **Unresolvable listener.** Logged and skipped, matching
 *   `ServiceEventListener`'s `QueryException` branch.
 * - **Thrown handler.** Propagates. Symfony's dispatcher does not catch
 *   listener exceptions either, so a throwing listener already aborted the
 *   remaining ones; routing through the proxy does not change that.
 * - **Order.** Subscriptions run in declaration order among themselves, but as
 *   a group they occupy the proxy's slot in the dispatcher rather than their
 *   original ones. This is a real, deliberate change. It is safe for the `*ed`
 *   post-events this mechanism targets, which expose neither mutation nor a
 *   veto, so nothing downstream can observe the reordering.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ObjectEventProxyListener implements IEventListener {

	/**
	 * App config key for the runtime kill switch.
	 *
	 * @var string
	 */
	private const KILL_SWITCH = 'objectEventFilter';

	/**
	 * Seconds a resolved slug-to-id map stays in the local cache.
	 *
	 * @var integer
	 */
	private const MAP_TTL = 60;

	/**
	 * Layout of one trace log line.
	 *
	 * @var string
	 */
	private const TRACE_FORMAT = "%s proxy subscriptions=%d dispatches=%d invoked=%d skipped=%d"
		. " proxyUs=%.1f mapUs=%.1f steadyPerDispatchUs=%.1f listenerUs=%.1f\n";

	/**
	 * Memoised listener instances, keyed by class name.
	 *
	 * Deliberately an INSTANCE property, not a static. Nextcloud builds a fresh
	 * `ServiceEventListener` — and therefore a fresh proxy — for every request,
	 * but a `static` survives the whole php-fpm worker. Caching a resolved
	 * service statically would hand request N+1 a listener wired to request N's
	 * container. Everything memoised here is per-request for that reason.
	 *
	 * @var array<string, IEventListener|null>
	 */
	private array $resolved = [];

	/**
	 * Register ids matching a declared register token, keyed by lower-cased token.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $registerIds = null;

	/**
	 * Schema ids matching a declared schema token, keyed by lower-cased token.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $schemaIds = null;

	/**
	 * Whether the filter is on; null until read once per request.
	 *
	 * @var boolean|null
	 */
	private ?bool $filterOn = null;

	/**
	 * Whether the overhead trace is armed; null until resolved once per request.
	 *
	 * @var boolean|null
	 */
	private ?bool $traceOn = null;

	/**
	 * Whether the trace flush has been registered for this request.
	 *
	 * @var boolean
	 */
	private bool $traceArmed = false;

	/**
	 * Trace counters: dispatches, invoked, skipped, proxy microseconds,
	 * listener microseconds.
	 *
	 * @var array<string, float>
	 */
	private array $trace = [
		'dispatch' => 0.0,
		'invoked' => 0.0,
		'skipped' => 0.0,
		'proxyUs' => 0.0,
		'listenerUs' => 0.0,
		'mapUs' => 0.0,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container.
	 * @param IAppConfig $appConfig App config, for the kill switch.
	 * @param ICacheFactory $cacheFactory Cache factory for the slug map.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Route one dispatched event to the subscriptions that want it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function handle(Event $event): void {
		$subscriptions = ObjectEventSubscription::subscriptionsFor($event::class);
		if ($subscriptions === []) {
			return;
		}

		$start = $this->traceDispatch();
		$target = $this->identify(event: $event);

		$listenerNs = 0.0;
		foreach ($subscriptions as $subscription) {
			$listenerNs += $this->invoke(subscription: $subscription, event: $event, target: $target);
		}

		$this->traceTotals(start: $start, listenerNs: $listenerNs);

	}//end handle()

	/**
	 * Invoke one subscription when it wants the written object.
	 *
	 * @param array{listener: string, registers: array<int,string>|null, schemas: array<int,string>|null} $subscription The declaration.
	 * @param Event $event The dispatched event.
	 * @param array{0: string|null, 1: string|null, 2: bool} $target What identify() read off the event.
	 *
	 * @return float Nanoseconds spent inside the listener while tracing, else 0.
	 */
	private function invoke(array $subscription, Event $event, array $target): float {
		if ($this->wants(subscription: $subscription, target: $target) === false) {
			$this->traceCount(counter: 'skipped');
			return 0.0;
		}

		$listener = $this->resolve(class: $subscription['listener']);
		if ($listener === null) {
			return 0.0;
		}

		if ($this->traceOn !== true) {
			$listener->handle($event);
			return 0.0;
		}

		return $this->timedHandle(listener: $listener, event: $event);
	}//end invoke()

	/**
	 * Run one listener while tracing and count it.
	 *
	 * @param IEventListener<Event> $listener The listener.
	 * @param Event                 $event    The dispatched event.
	 *
	 * @return float Nanoseconds spent inside the listener.
	 */
	private function timedHandle(IEventListener $listener, Event $event): float {
		$this->trace['invoked']++;
		$handlerStart = (float)hrtime(true);
		$listener->handle($event);
		return ((float)hrtime(true) - $handlerStart);
	}//end timedHandle()

	/**
	 * Start the trace of one dispatch.
	 *
	 * @return float hrtime at the start of the dispatch, or 0 when not tracing.
	 */
	private function traceDispatch(): float {
		if ($this->traceEnabled() === false) {
			return 0.0;
		}

		$this->trace['dispatch']++;
		return (float)hrtime(true);
	}//end traceDispatch()

	/**
	 * Count one trace event while tracing.
	 *
	 * @param string $counter The trace counter to raise.
	 *
	 * @return void
	 */
	private function traceCount(string $counter): void {
		if ($this->traceOn === true) {
			$this->trace[$counter]++;
		}
	}//end traceCount()

	/**
	 * Add one dispatch's proxy and listener time to the trace.
	 *
	 * @param float $start      hrtime at the start of the dispatch.
	 * @param float $listenerNs Nanoseconds spent inside listeners.
	 *
	 * @return void
	 */
	private function traceTotals(float $start, float $listenerNs): void {
		if ($this->traceOn !== true) {
			return;
		}

		$total = ((float)hrtime(true) - $start);
		$this->trace['proxyUs'] += (($total - $listenerNs) / 1000);
		$this->trace['listenerUs'] += ($listenerNs / 1000);
	}//end traceTotals()

	/**
	 * Read the written object's register and schema ids off the event.
	 *
	 * The third element says whether the event carries an `ObjectEntity` at
	 * all. Without one (a merge) the caller cannot apply a filter and invokes.
	 * With one, a null id means the object has no register or schema, which a
	 * filtered subscription reads as "not mine" and skips (fail closed).
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string|null, 1: string|null, 2: bool}
	 */
	private function identify(Event $event): array {
		$object = $this->writtenObject(event: $event);
		if (($object instanceof ObjectEntity) === false) {
			return [null, null, false];
		}

		return [$this->idOf(value: $object->getRegister()), $this->idOf(value: $object->getSchema()), true];
	}//end identify()

	/**
	 * The object an event carries, whichever accessor it uses.
	 *
	 * ObjectUpdatingEvent carries the written object as getNewObject() and
	 * has no getObject(). Reading only getObject() left every pre-update
	 * event undecidable, so every ObjectUpdatingEvent subscriber ran on
	 * every update in every register, whatever it declared.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return mixed The carried object, or null.
	 */
	private function writtenObject(Event $event): mixed {
		if (method_exists($event, 'getObject') === true) {
			return $event->getObject();
		}

		if (method_exists($event, 'getNewObject') === true) {
			return $event->getNewObject();
		}

		return null;
	}//end writtenObject()

	/**
	 * A register or schema reference as a string id.
	 *
	 * @param mixed $value The reference the object carries.
	 *
	 * @return string|null Null when the object carries none.
	 */
	private function idOf(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end idOf()

	/**
	 * Decide whether one subscription wants this object.
	 *
	 * No object on the event (a merge): a filter cannot be applied, and
	 * skipping would leave every filtered listener on it silently dead.
	 *
	 * @param array{listener: string, registers: array<int,string>|null, schemas: array<int,string>|null} $subscription The declaration.
	 * @param array{0: string|null, 1: string|null, 2: bool} $target Register id, schema id, and whether the event carries an object.
	 *
	 * @return boolean
	 */
	private function wants(array $subscription, array $target): bool {
		if ($this->filterEnabled() === false || $target[2] === false) {
			return true;
		}

		return $this->filterAccepts(tokens: $subscription['registers'], id: $target[0], isSchema: false)
			&& $this->filterAccepts(tokens: $subscription['schemas'], id: $target[1], isSchema: true);
	}//end wants()

	/**
	 * Whether one declared filter (registers or schemas) accepts the object.
	 *
	 * An object without a register or schema is not in the declared one:
	 * fail closed. Invoking here is how a listener filtered to one schema
	 * once ran on every update instance-wide (live defect H1).
	 *
	 * @param array<int,string>|null $tokens   Declared tokens, or null for no filter.
	 * @param string|null            $id       The written object's register/schema id.
	 * @param boolean                $isSchema True for schemas, false for registers.
	 *
	 * @return boolean
	 */
	private function filterAccepts(?array $tokens, ?string $id, bool $isSchema): bool {
		if ($tokens === null) {
			return true;
		}

		if ($id === null) {
			return false;
		}

		return $this->tokensMatch(tokens: $tokens, id: $id, isSchema: $isSchema);
	}//end filterAccepts()

	/**
	 * Whether any declared token resolves to the given id.
	 *
	 * Tokens are classified, not tried both ways. An all-digit token is an
	 * **id**: it is compared directly and never resolved as a slug. Anything
	 * else is a **slug**: it is answered from the per-request map and never
	 * compared as an id.
	 *
	 * The classification is the load-bearing half of the numeric-declaration
	 * fix. Keeping ids out of the slug path is not an optimisation — a numeric
	 * token resolved as a slug matches no row, so the subscription would never
	 * fire and the listener would be silently dead. That failure is strictly
	 * worse than the `TypeError` it replaced, because a 500 is at least loud.
	 *
	 * @param array<int,string> $tokens Declared slugs and/or ids.
	 * @param string $id The written object's register/schema id.
	 * @param boolean $isSchema True for schemas, false for registers.
	 *
	 * @return boolean
	 */
	private function tokensMatch(array $tokens, string $id, bool $isSchema): bool {
		$slugs = [];
		foreach ($tokens as $token) {
			$token = (string)$token;
			if (ObjectEventSubscription::isIdToken($token) === false) {
				$slugs[] = $token;
				continue;
			}

			if ($token === $id) {
				return true;
			}
		}

		return $slugs !== [] && $this->slugsMatch(slugs: $slugs, id: $id, isSchema: $isSchema);
	}//end tokensMatch()

	/**
	 * Whether any declared slug resolves to the given id.
	 *
	 * @param array<int,string> $slugs    Declared slugs.
	 * @param string            $id       The written object's register/schema id.
	 * @param boolean           $isSchema True for schemas, false for registers.
	 *
	 * @return boolean
	 */
	private function slugsMatch(array $slugs, string $id, bool $isSchema): bool {
		$map = $this->tokenMap(isSchema: $isSchema);
		foreach ($slugs as $slug) {
			if (in_array($id, ($map[strtolower($slug)] ?? []), true) === true) {
				return true;
			}
		}

		return false;
	}//end slugsMatch()

	/**
	 * Per-request map of declared slug to the ids it resolves to.
	 *
	 * Built ONCE per request for every slug declared by every app, and cached
	 * in the local (APCu) cache for {@see self::MAP_TTL} seconds on top of
	 * that. This is the load-bearing part of the mechanism: a per-dispatch slug
	 * lookup would be a database read per object write, which would cost more
	 * than the handlers it skips.
	 *
	 * Measured on the reference instance: neither the registers nor the
	 * schemas table carries an index on `slug`, so each lookup is a
	 * sequential scan — 1,137 us for the two of them, against ~29 us of
	 * steady-state per-dispatch filtering. Uncached, the fixed cost dominated
	 * the mechanism.
	 *
	 * The TTL is the price: a schema created in the last minute may not be
	 * matched yet, so a listener declaring its slug is invoked one cache
	 * generation late. Registers and schemas are configuration, written by
	 * import and admin action rather than by traffic, so a bounded minute of
	 * staleness is the right trade against a query on every object write.
	 *
	 * @param boolean $isSchema True for schemas, false for registers.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function tokenMap(bool $isSchema): array {
		$known = $this->knownMap(isSchema: $isSchema);
		if ($known !== null) {
			return $known;
		}

		$mapStart = (float)hrtime(true);
		$tokens = $this->declaredTokens(isSchema: $isSchema);
		$map = array_fill_keys(array_map('strtolower', $tokens), []);

		if ($tokens !== []) {
			$map = $this->resolveMap(tokens: $tokens, isSchema: $isSchema, fallback: $map);
		}

		return $this->rememberMap(isSchema: $isSchema, map: $map, mapStart: $mapStart);
	}//end tokenMap()

	/**
	 * The map already resolved this request, if any.
	 *
	 * @param boolean $isSchema True for schemas, false for registers.
	 *
	 * @return array<string, array<int, string>>|null
	 */
	private function knownMap(bool $isSchema): ?array {
		if ($isSchema === true) {
			return $this->schemaIds;
		}

		return $this->registerIds;
	}//end knownMap()

	/**
	 * The slug tokens every app declared for registers or schemas.
	 *
	 * @param boolean $isSchema True for schemas, false for registers.
	 *
	 * @return array<int, string>
	 */
	private function declaredTokens(bool $isSchema): array {
		if ($isSchema === true) {
			return ObjectEventSubscription::declaredSchemaTokens();
		}

		return ObjectEventSubscription::declaredRegisterTokens();
	}//end declaredTokens()

	/**
	 * Answer a slug map from the local cache, else from the mapper.
	 *
	 * @param array<int, string>                $tokens   Declared slugs.
	 * @param boolean                           $isSchema True for schemas, false for registers.
	 * @param array<string, array<int, string>> $fallback The empty-but-built map kept when resolution fails.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function resolveMap(array $tokens, bool $isSchema, array $fallback): array {
		sort($tokens);

		$prefix = 'r:';
		if ($isSchema === true) {
			$prefix = 's:';
		}

		$cacheKey = $prefix . md5(implode(',', $tokens));
		$cache = $this->cacheFactory->createLocal('openregister_event_interest');
		$cached = $this->cachedMap(cache: $cache, cacheKey: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		return ($this->fetchMap(tokens: $tokens, isSchema: $isSchema, cache: $cache, cacheKey: $cacheKey) ?? $fallback);
	}//end resolveMap()

	/**
	 * A slug map from the local cache.
	 *
	 * @param ICache $cache    The local cache.
	 * @param string $cacheKey The map's key.
	 *
	 * @return array<string, array<int, string>>|null Null on a miss or an undecodable entry.
	 */
	private function cachedMap(ICache $cache, string $cacheKey): ?array {
		$cached = $cache->get($cacheKey);
		if (is_string($cached) === false) {
			return null;
		}

		$decoded = json_decode($cached, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end cachedMap()

	/**
	 * A slug map from the register or schema mapper, stored in the local cache.
	 *
	 * A resolution failure must not swallow the listener: the caller keeps the
	 * map empty-but-built and every declaration falls through to the id
	 * comparison. Logged, never silent.
	 *
	 * @param array<int, string> $tokens   Declared slugs, sorted.
	 * @param boolean            $isSchema True for schemas, false for registers.
	 * @param ICache             $cache    The local cache.
	 * @param string             $cacheKey The map's key.
	 *
	 * @return array<string, array<int, string>>|null Null when the lookup failed.
	 */
	private function fetchMap(array $tokens, bool $isSchema, ICache $cache, string $cacheKey): ?array {
		$mapperClass = RegisterMapperInterface::class;
		if ($isSchema === true) {
			$mapperClass = SchemaMapperInterface::class;
		}

		$map = null;
		try {
			$map = $this->container->get($mapperClass)->findIdsBySlugs($tokens);
			$cache->set($cacheKey, json_encode($map), self::MAP_TTL);
		} catch (\Throwable $e) {
			$this->logger->warning('Filtered object-event subscription could not resolve slugs: ' . $e->getMessage(), ['exception' => $e]);
		}

		return $map;
	}//end fetchMap()

	/**
	 * Memoise a resolved token map for the rest of the request.
	 *
	 * @param boolean $isSchema True for schemas, false for registers.
	 * @param array<string, array<int, string>> $map The resolved map.
	 * @param float $mapStart hrtime at which resolution started.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function rememberMap(bool $isSchema, array $map, float $mapStart): array {
		if ($isSchema === true) {
			$this->schemaIds = $map;
		} else {
			$this->registerIds = $map;
		}

		$this->trace['mapUs'] += (((float)hrtime(true) - $mapStart) / 1000);

		return $map;
	}//end rememberMap()

	/**
	 * Resolve and memoise a subscribed listener.
	 *
	 * @param string $class Listener class name.
	 *
	 * @return IEventListener|null Null when the class cannot be resolved.
	 */
	private function resolve(string $class): ?IEventListener {
		if (array_key_exists($class, $this->resolved) === false) {
			$this->resolved[$class] = $this->load(class: $class);
		}

		return $this->resolved[$class];
	}//end resolve()

	/**
	 * Load a subscribed listener from the container.
	 *
	 * @param string $class Listener class name.
	 *
	 * @return IEventListener|null Null, logged, when it cannot be loaded or is no listener.
	 */
	private function load(string $class): ?IEventListener {
		try {
			$listener = $this->container->get($class);
		} catch (\Throwable $e) {
			$this->logger->error('Could not load filtered event listener service ' . $class . ': ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}

		if (($listener instanceof IEventListener) === false) {
			$this->logger->error('Filtered event listener ' . $class . ' does not implement IEventListener');
			return null;
		}

		return $listener;
	}//end load()

	/**
	 * Whether filtering is active.
	 *
	 * `occ config:app:set openregister objectEventFilter --value off` restores
	 * the pre-change behaviour instance-wide: every subscription is invoked on
	 * every dispatch, exactly as a global registration would. That is also the
	 * A/B knob, so both arms of a measurement run the same code at the same
	 * host load rather than being separate deploys minutes apart.
	 *
	 * @return boolean
	 */
	private function filterEnabled(): bool {
		if ($this->filterOn === null) {
			$this->filterOn = ($this->appConfig->getValueString('openregister', self::KILL_SWITCH, 'on') !== 'off');
		}

		return $this->filterOn;
	}//end filterEnabled()

	/**
	 * Whether the overhead trace is armed.
	 *
	 * Shares the write-path probe's flag file so one `touch` arms both, and
	 * costs a single `file_exists` per process when off.
	 *
	 * @return boolean
	 */
	private function traceEnabled(): bool {
		if ($this->traceOn === null) {
			$this->traceOn = @file_exists('/tmp/or-trace-write-phases');
		}

		if ($this->traceOn === true && $this->traceArmed === false) {
			$this->traceArmed = true;
			register_shutdown_function(
				function (): void {
					$this->flushTrace();
				}
			);
		}

		return $this->traceOn;
	}//end traceEnabled()

	/**
	 * Append this request's proxy overhead to the trace log.
	 *
	 * `proxyUs` is the proxy's OWN cost: total time inside handle() minus the
	 * time spent inside the listener handlers it invoked. That is the number
	 * the mechanism has to beat, since a filter that costs more than the
	 * handlers it skips is a net loss.
	 *
	 * @return void
	 */
	private function flushTrace(): void {
		if ($this->trace['dispatch'] <= 0.0) {
			return;
		}

		@file_put_contents('/tmp/or-event-proxy.log', $this->traceLine(), (FILE_APPEND | LOCK_EX));

	}//end flushTrace()

	/**
	 * This request's trace counters as one log line.
	 *
	 * @return string
	 */
	private function traceLine(): string {
		$trace = $this->trace;
		return sprintf(
			self::TRACE_FORMAT,
			date('H:i:s'),
			ObjectEventSubscription::subscriptionCount(),
			(int)$trace['dispatch'],
			(int)$trace['invoked'],
			(int)$trace['skipped'],
			$trace['proxyUs'],
			$trace['mapUs'],
			(($trace['proxyUs'] - $trace['mapUs']) / $trace['dispatch']),
			$trace['listenerUs']
		);
	}//end traceLine()
}//end class
