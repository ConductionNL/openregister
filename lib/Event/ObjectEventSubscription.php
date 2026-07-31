<?php

/**
 * Filtered object-event subscription.
 *
 * Registration-time declaration of the registers and schemas a listener cares
 * about, so an uninterested listener is never invoked instead of being invoked
 * and bailing out in its own handler body.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Listener\ObjectEventProxyListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registry + registration helper for filtered object-event subscription.
 *
 * Usage from a leaf app's `Application::register()`, in place of
 * `$context->registerEventListener(...)`:
 *
 * ```php
 * \OCA\OpenRegister\Event\ObjectEventSubscription::register(
 *     context:   $context,
 *     event:     ObjectCreatedEvent::class,
 *     listener:  BezwaarLifecycleListener::class,
 *     registers: ['procest'],
 *     schemas:   ['bezwaar', 'hoorzitting'],
 * );
 * ```
 *
 * Semantics:
 *
 * - `registers` / `schemas` accept register/schema **slugs** or numeric ids.
 *   A numeric id is compared directly; a slug is resolved once per request.
 * - Passing `null` (the default) for either means "all", so a subscription
 *   that declares neither behaves exactly like today's global registration.
 *   This is therefore a strictly opt-in narrowing.
 * - Both filters must pass. `registers: ['procest'], schemas: ['decision']`
 *   means "the `decision` schema of the `procest` register", which is how a
 *   slug that collides across apps stays unambiguous.
 * - When the dispatched event carries no resolvable `ObjectEntity` the
 *   subscription is invoked regardless of its declaration. Fail-open: a
 *   filter that cannot decide must not silently drop work.
 *
 * All state is per-process. Nextcloud rebuilds the registration context on
 * every request, so nothing here survives a request boundary.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ObjectEventSubscription
{

    /**
     * Declared subscriptions, keyed by event class name.
     *
     * @var array<string, array<int, array{listener: string, registers: array<int, string>|null, schemas: array<int, string>|null}>>
     */
    private static array $subscriptions = [];

    /**
     * Event class names for which the shared proxy has been registered.
     *
     * @var array<string, boolean>
     */
    private static array $proxied = [];

    /**
     * Token identifying the request the current state belongs to.
     *
     * @var string|null
     */
    private static ?string $requestToken = null;

    /**
     * Discard state left over from a previous request in this worker.
     *
     * A php-fpm worker serves many requests and static properties survive all
     * of them, while Nextcloud rebuilds the registration context on every one.
     * Without this guard the registry would accumulate a fresh copy of every
     * subscription per request — and, worse, `$proxied` would suppress the
     * proxy registration from request two onwards, so every subscribed listener
     * would silently stop being invoked after the first request a worker served.
     *
     * @return void
     */
    private static function scopeToRequest(): void
    {
        $token = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? 'no-request');
        if (self::$requestToken === $token) {
            return;
        }

        self::$requestToken  = $token;
        self::$subscriptions = [];
        self::$proxied       = [];

    }//end scopeToRequest()

    /**
     * Declare a filtered subscription and make sure the proxy is registered.
     *
     * The proxy is registered at most once per event class, no matter how many
     * apps subscribe, because every listener registered through
     * `IRegistrationContext` is resolved from the **server** container anyway
     * (`OC\EventDispatcher\EventDispatcher::addServiceListener()` hands its own
     * container to `ServiceEventListener`). Which app's `$context` performs the
     * registration is therefore immaterial to resolution.
     *
     * Priority is deliberately not exposed: the proxy occupies a single slot in
     * the dispatcher, so a per-subscription priority could not be honoured
     * without splitting the proxy again. A listener that needs a non-default
     * priority must keep using `registerEventListener()`.
     *
     * @param IRegistrationContext   $context   The registering app's context.
     * @param string                 $event     Event class name to subscribe to.
     * @param string                 $listener  IEventListener class to invoke.
     * @param array<int,string>|null $registers Register slugs/ids, or null for all.
     * @param array<int,string>|null $schemas   Schema slugs/ids, or null for all.
     *
     * @return void
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public static function register(
        IRegistrationContext $context,
        string $event,
        string $listener,
        ?array $registers=null,
        ?array $schemas=null
    ): void {
        self::scopeToRequest();

        if (isset(self::$proxied[$event]) === false) {
            $context->registerEventListener($event, ObjectEventProxyListener::class);
            self::$proxied[$event] = true;
        }

        self::$subscriptions[$event][] = [
            'listener'  => $listener,
            'registers' => self::normalise(tokens: $registers),
            'schemas'   => self::normalise(tokens: $schemas),
        ];

    }//end register()

    /**
     * Subscriptions declared for one event class.
     *
     * @param string $event Event class name.
     *
     * @return array<int, array{listener: string, registers: array<int, string>|null, schemas: array<int, string>|null}>
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public static function subscriptionsFor(string $event): array
    {
        self::scopeToRequest();

        return (self::$subscriptions[$event] ?? []);

    }//end subscriptionsFor()

    /**
     * Every distinct register token declared anywhere in this process.
     *
     * The proxy resolves these in one bounded query rather than one query per
     * dispatch, which is what keeps the filter cheaper than the handlers it
     * skips.
     *
     * @return array<int, string>
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public static function declaredRegisterTokens(): array
    {
        return self::collect(field: 'registers');

    }//end declaredRegisterTokens()

    /**
     * Every distinct schema token declared anywhere in this process.
     *
     * @return array<int, string>
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public static function declaredSchemaTokens(): array
    {
        return self::collect(field: 'schemas');

    }//end declaredSchemaTokens()

    /**
     * Drop all declarations. Test seam only.
     *
     * @return void
     *
     * @spec openspec/specs/event-driven-architecture/spec.md
     */
    public static function reset(): void
    {
        self::$subscriptions = [];
        self::$proxied       = [];
        self::$requestToken  = null;

    }//end reset()

    /**
     * Collect the distinct tokens of one declaration field.
     *
     * @param string $field Either `registers` or `schemas`.
     *
     * @return array<int, string>
     */
    private static function collect(string $field): array
    {
        $tokens = [];
        foreach (self::$subscriptions as $entries) {
            foreach ($entries as $entry) {
                if ($entry[$field] === null) {
                    continue;
                }

                foreach ($entry[$field] as $token) {
                    $tokens[$token] = true;
                }
            }
        }

        return array_keys($tokens);

    }//end collect()

    /**
     * Normalise a declaration list, collapsing an empty list to null.
     *
     * An empty array means "the app declared nothing", which must mean "all"
     * rather than "nothing" — the opposite reading would silently disable a
     * listener whose slug list came from an empty config value.
     *
     * @param array<int,string>|null $tokens Raw declaration.
     *
     * @return array<int,string>|null
     */
    private static function normalise(?array $tokens): ?array
    {
        if ($tokens === null) {
            return null;
        }

        $clean = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                $clean[$token] = true;
            }
        }

        if ($clean === []) {
            return null;
        }

        return array_keys($clean);

    }//end normalise()
}//end class
