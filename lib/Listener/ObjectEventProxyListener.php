<?php

/**
 * Shared proxy for filtered object-event subscriptions.
 *
 * One instance of this listener stands in for every subscription declared via
 * {@see \OCA\OpenRegister\Event\ObjectEventSubscription::register()}. On
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
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
final class ObjectEventProxyListener implements IEventListener
{

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
        'dispatch'   => 0.0,
        'invoked'    => 0.0,
        'skipped'    => 0.0,
        'proxyUs'    => 0.0,
        'listenerUs' => 0.0,
        'mapUs'      => 0.0,
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container    The server container.
     * @param IAppConfig         $appConfig    App config, for the kill switch.
     * @param ICacheFactory      $cacheFactory Cache factory for the slug map.
     * @param LoggerInterface    $logger       Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
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
    public function handle(Event $event): void
    {
        $subscriptions = ObjectEventSubscription::subscriptionsFor($event::class);
        if ($subscriptions === []) {
            return;
        }

        $trace = $this->traceEnabled();
        $begin = 0.0;
        if ($trace === true) {
            $begin = (float) hrtime(true);
            $this->trace['dispatch']++;
        }

        [$registerId, $schemaId] = $this->identify(event: $event);

        $listenerNs = 0.0;
        foreach ($subscriptions as $subscription) {
            if ($this->wants(subscription: $subscription, registerId: $registerId, schemaId: $schemaId) === false) {
                if ($trace === true) {
                    $this->trace['skipped']++;
                }

                continue;
            }

            $listener = $this->resolve(class: $subscription['listener']);
            if ($listener === null) {
                continue;
            }

            if ($trace === true) {
                $this->trace['invoked']++;
                $handlerBegin = (float) hrtime(true);
                $listener->handle($event);
                $listenerNs += ((float) hrtime(true) - $handlerBegin);
                continue;
            }

            $listener->handle($event);
        }//end foreach

        if ($trace === true) {
            $total = ((float) hrtime(true) - $begin);
            $this->trace['proxyUs']    += (($total - $listenerNs) / 1000);
            $this->trace['listenerUs'] += ($listenerNs / 1000);
        }

    }//end handle()

    /**
     * Read the written object's register and schema ids off the event.
     *
     * Both are null when the event carries no `ObjectEntity`, which the caller
     * reads as "cannot decide" and therefore "invoke".
     *
     * @param Event $event The dispatched event.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function identify(Event $event): array
    {
        if (method_exists($event, 'getObject') === false) {
            return [null, null];
        }

        $object = $event->getObject();
        if (($object instanceof ObjectEntity) === false) {
            return [null, null];
        }

        $register = $object->getRegister();
        $schema   = $object->getSchema();

        if ($register === null && $schema === null) {
            return [null, null];
        }

        $registerId = null;
        if ($register !== null) {
            $registerId = (string) $register;
        }

        $schemaId = null;
        if ($schema !== null) {
            $schemaId = (string) $schema;
        }

        return [$registerId, $schemaId];

    }//end identify()

    /**
     * Decide whether one subscription wants this object.
     *
     * @param array{listener: string, registers: array<int,string>|null, schemas: array<int,string>|null} $subscription The declaration.
     * @param string|null                                                                                 $registerId   Written object's register id.
     * @param string|null                                                                                 $schemaId     Written object's schema id.
     *
     * @return boolean
     */
    private function wants(array $subscription, ?string $registerId, ?string $schemaId): bool
    {
        if ($this->filterEnabled() === false) {
            return true;
        }

        if ($subscription['registers'] !== null && $registerId !== null) {
            if ($this->tokensMatch(tokens: $subscription['registers'], id: $registerId, isSchema: false) === false) {
                return false;
            }
        }

        if ($subscription['schemas'] !== null && $schemaId !== null) {
            if ($this->tokensMatch(tokens: $subscription['schemas'], id: $schemaId, isSchema: true) === false) {
                return false;
            }
        }

        return true;

    }//end wants()

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
     * @param array<int,string> $tokens   Declared slugs and/or ids.
     * @param string            $id       The written object's register/schema id.
     * @param boolean           $isSchema True for schemas, false for registers.
     *
     * @return boolean
     */
    private function tokensMatch(array $tokens, string $id, bool $isSchema): bool
    {
        $slugs = [];
        foreach ($tokens as $token) {
            $token = (string) $token;
            if (ObjectEventSubscription::isIdToken($token) === true) {
                if ($token === $id) {
                    return true;
                }

                continue;
            }

            $slugs[] = $token;
        }

        if ($slugs === []) {
            return false;
        }

        $map = $this->tokenMap(isSchema: $isSchema);
        foreach ($slugs as $slug) {
            if (in_array($id, ($map[strtolower($slug)] ?? []), true) === true) {
                return true;
            }
        }

        return false;

    }//end tokensMatch()

    /**
     * Per-request map of declared slug to the ids it resolves to.
     *
     * Built ONCE per request for every slug declared by every app, and cached
     * in the local (APCu) cache for {@see self::MAP_TTL} seconds on top of
     * that. This is the load-bearing part of the mechanism: a per-dispatch slug
     * lookup would be a database read per object write, which would cost more
     * than the handlers it skips.
     *
     * Measured on the reference instance: neither `oc_openregister_registers`
     * nor `oc_openregister_schemas` carries an index on `slug`, so each lookup
     * is a sequential scan — 1,137 us for the two of them, against ~29 us of
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
    private function tokenMap(bool $isSchema): array
    {
        if ($isSchema === true && $this->schemaIds !== null) {
            return $this->schemaIds;
        }

        if ($isSchema === false && $this->registerIds !== null) {
            return $this->registerIds;
        }

        $mapBegin = (float) hrtime(true);

        $tokens = ObjectEventSubscription::declaredSchemaTokens();
        if ($isSchema === false) {
            $tokens = ObjectEventSubscription::declaredRegisterTokens();
        }

        $map = [];
        foreach ($tokens as $token) {
            $map[strtolower($token)] = [];
        }

        if ($tokens !== []) {
            sort($tokens);

            $prefix = 'r:';
            if ($isSchema === true) {
                $prefix = 's:';
            }

            $cacheKey = $prefix.md5(implode(',', $tokens));
            $cache    = $this->cacheFactory->createLocal('openregister_event_interest');
            $cached   = $cache->get($cacheKey);

            if (is_string($cached) === true) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) === true) {
                    $map = $decoded;
                    return $this->rememberMap(isSchema: $isSchema, map: $map, mapBegin: $mapBegin);
                }
            }

            try {
                if ($isSchema === true) {
                    $mapper = $this->container->get(SchemaMapper::class);
                } else {
                    $mapper = $this->container->get(RegisterMapper::class);
                }

                $map = $mapper->findIdsBySlugs($tokens);

                $cache->set($cacheKey, json_encode($map), self::MAP_TTL);
            } catch (\Throwable $e) {
                // A resolution failure must not swallow the listener: leave the
                // map empty-but-built and let every declaration fall through to
                // the id comparison. Logged, never silent.
                $this->logger->warning(
                    'Filtered object-event subscription could not resolve slugs: '.$e->getMessage(),
                    ['exception' => $e]
                );
            }//end try
        }//end if

        return $this->rememberMap(isSchema: $isSchema, map: $map, mapBegin: $mapBegin);

    }//end tokenMap()

    /**
     * Memoise a resolved token map for the rest of the request.
     *
     * @param boolean                           $isSchema True for schemas, false for registers.
     * @param array<string, array<int, string>> $map      The resolved map.
     * @param float                             $mapBegin hrtime at which resolution started.
     *
     * @return array<string, array<int, string>>
     */
    private function rememberMap(bool $isSchema, array $map, float $mapBegin): array
    {
        if ($isSchema === true) {
            $this->schemaIds = $map;
        } else {
            $this->registerIds = $map;
        }

        $this->trace['mapUs'] += (((float) hrtime(true) - $mapBegin) / 1000);

        return $map;

    }//end rememberMap()

    /**
     * Resolve and memoise a subscribed listener.
     *
     * @param string $class Listener class name.
     *
     * @return IEventListener|null Null when the class cannot be resolved.
     */
    private function resolve(string $class): ?IEventListener
    {
        if (array_key_exists($class, $this->resolved) === true) {
            return $this->resolved[$class];
        }

        try {
            $listener = $this->container->get($class);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Could not load filtered event listener service '.$class.': '.$e->getMessage(),
                ['exception' => $e]
            );
            $this->resolved[$class] = null;
            return null;
        }

        if (($listener instanceof IEventListener) === false) {
            $this->logger->error('Filtered event listener '.$class.' does not implement IEventListener');
            $this->resolved[$class] = null;
            return null;
        }

        $this->resolved[$class] = $listener;

        return $listener;

    }//end resolve()

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
    private function filterEnabled(): bool
    {
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
    private function traceEnabled(): bool
    {
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
    private function flushTrace(): void
    {
        if ($this->trace['dispatch'] <= 0.0) {
            return;
        }

        $line = sprintf(
            "%s proxy subscriptions=%d dispatches=%d invoked=%d skipped=%d proxyUs=%.1f mapUs=%.1f steadyPerDispatchUs=%.1f listenerUs=%.1f\n",
            date('H:i:s'),
            ObjectEventSubscription::subscriptionCount(),
            (int) $this->trace['dispatch'],
            (int) $this->trace['invoked'],
            (int) $this->trace['skipped'],
            $this->trace['proxyUs'],
            $this->trace['mapUs'],
            (($this->trace['proxyUs'] - $this->trace['mapUs']) / $this->trace['dispatch']),
            $this->trace['listenerUs']
        );

        @file_put_contents('/tmp/or-event-proxy.log', $line, (FILE_APPEND | LOCK_EX));

    }//end flushTrace()
}//end class
