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
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Registry + registration helper for filtered object-event subscription.
 *
 * Usage from a leaf app's `Application::boot()`, in place of
 * `$context->registerEventListener(...)`:
 *
 * ```php
 * public function boot(IBootContext $context): void
 * {
 *     $dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);
 *
 *     \OCA\OpenRegister\Event\ObjectEventSubscription::subscribe(
 *         dispatcher: $dispatcher,
 *         event:      ObjectCreatedEvent::class,
 *         listener:   BezwaarLifecycleListener::class,
 *         registers:  ['procest'],
 *         schemas:    ['bezwaar', 'hoorzitting'],
 *     );
 * }
 * ```
 *
 * **Subscribe from `boot()`, not from `register()`.** Nextcloud enables each
 * app's autoloader immediately before calling that app's `register()`
 * (`OC\AppFramework\Bootstrap\Coordinator::registerApps()`), so this class is
 * only autoloadable from `register()` for apps whose id sorts after
 * `openregister`. Every earlier app saw `class_exists()` return false and
 * silently fell back to an unfiltered registration — seven of the first
 * twenty-seven conversions were inert for exactly this reason. `boot()` runs
 * only after every app's `register()` has completed, so by then every
 * autoloader is enabled and the guard resolves regardless of app order.
 * {@see self::subscribe()} exists for that call site; {@see self::register()}
 * is retained for OpenRegister's own listeners, which are by definition never
 * too early for their own autoloader.
 *
 * Semantics:
 *
 * - `registers` / `schemas` accept register/schema **slugs** or numeric ids.
 *   A token consisting only of digits is treated as an **id** and compared
 *   directly against the written object's register/schema id; it is never
 *   resolved as a slug. Any other token is resolved as a slug, once per
 *   request. Both kinds are honoured; neither is silently dropped.
 * - **An entirely numeric slug is therefore unsupported** — `'42'` always
 *   means "id 42", never "the schema whose slug is `42`". This is a deliberate
 *   trade: ids are the form the entity actually carries, so reading a digit
 *   string as an id is the interpretation that matches at zero cost, while
 *   an all-digit slug is not a shape OpenRegister's slug generator produces.
 *   Declare such a schema by id, or give it a non-numeric slug.
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
final class ObjectEventSubscription {

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
	private static function scopeToRequest(): void {
		$token = (string)($_SERVER['REQUEST_TIME_FLOAT'] ?? 'no-request');
		if (self::$requestToken === $token) {
			return;
		}

		self::$requestToken = $token;
		self::$subscriptions = [];
		self::$proxied = [];

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
	 * Prefer {@see self::subscribe()} from `boot()` in a leaf app: this method
	 * is only usable from `register()`, where whether this class is autoloadable
	 * at all depends on the calling app's position in the bootstrap order.
	 *
	 * @param IRegistrationContext $context The registering app's context.
	 * @param string $event Event class name to subscribe to.
	 * @param string $listener IEventListener class to invoke.
	 * @param array<int,string>|null $registers Register slugs/ids, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs/ids, or null for all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function register(
		IRegistrationContext $context,
		string $event,
		string $listener,
		?array $registers = null,
		?array $schemas = null,
	): void {
		self::scopeToRequest();

		if (isset(self::$proxied[$event]) === false) {
			$context->registerEventListener($event, ObjectEventProxyListener::class);
			self::$proxied[$event] = true;
		}

		self::record(event: $event, listener: $listener, registers: $registers, schemas: $schemas);

	}//end register()

	/**
	 * Declare a filtered subscription from `boot()`.
	 *
	 * This is the call site every leaf app should use. `boot()` runs after the
	 * whole `register()` pass, so this class is autoloadable here no matter
	 * where the subscribing app sits in the bootstrap order — which is the
	 * property `register()` cannot offer and the reason the boot-order-sensitive
	 * `class_exists()` guard silently disabled seven conversions.
	 *
	 * Listeners added to the live dispatcher in `boot()` are in place before any
	 * route is dispatched: `base.php` calls `IAppManager::loadApps()` — which
	 * boots every enabled app — before `Router::match()`, and `cron.php` and
	 * `occ` both do the same before doing any work. So a subscription made here
	 * is active for every object write on every entry path.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $event Event class name to subscribe to.
	 * @param string $listener IEventListener class to invoke.
	 * @param array<int,string>|null $registers Register slugs/ids, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs/ids, or null for all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function subscribe(
		IEventDispatcher $dispatcher,
		string $event,
		string $listener,
		?array $registers = null,
		?array $schemas = null,
	): void {
		self::scopeToRequest();

		if (isset(self::$proxied[$event]) === false) {
			$dispatcher->addServiceListener($event, ObjectEventProxyListener::class);
			self::$proxied[$event] = true;
		}

		self::record(event: $event, listener: $listener, registers: $registers, schemas: $schemas);

	}//end subscribe()

	/**
	 * Record one declaration in the registry.
	 *
	 * @param string $event Event class name.
	 * @param string $listener IEventListener class to invoke.
	 * @param array<int,string>|null $registers Register slugs/ids, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs/ids, or null for all.
	 *
	 * @return void
	 */
	private static function record(
		string $event,
		string $listener,
		?array $registers,
		?array $schemas,
	): void {
		self::$subscriptions[$event][] = [
			'listener' => $listener,
			'registers' => self::normalise(tokens: $registers),
			'schemas' => self::normalise(tokens: $schemas),
		];

	}//end record()

	/**
	 * Subscriptions declared for one event class.
	 *
	 * @param string $event Event class name.
	 *
	 * @return array<int, array{listener: string, registers: array<int, string>|null, schemas: array<int, string>|null}>
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function subscriptionsFor(string $event): array {
		self::scopeToRequest();

		return (self::$subscriptions[$event] ?? []);
	}//end subscriptionsFor()

	/**
	 * Every distinct register **slug** declared anywhere in this process.
	 *
	 * The proxy resolves these in one bounded query rather than one query per
	 * dispatch, which is what keeps the filter cheaper than the handlers it
	 * skips. Id tokens are not listed: they need no resolution.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function declaredRegisterTokens(): array {
		return self::collect(field: 'registers');
	}//end declaredRegisterTokens()

	/**
	 * Every distinct schema **slug** declared anywhere in this process.
	 *
	 * Id tokens are not listed: they need no resolution.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function declaredSchemaTokens(): array {
		return self::collect(field: 'schemas');
	}//end declaredSchemaTokens()

	/**
	 * Drop all declarations. Test seam only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function reset(): void {
		self::$subscriptions = [];
		self::$proxied = [];
		self::$requestToken = null;

	}//end reset()

	/**
	 * Whether a declared token denotes a register/schema id rather than a slug.
	 *
	 * `ctype_digit()` rather than `is_numeric()` on purpose: only an unsigned
	 * run of digits is an id. `-1`, `1.5` and `1e3` are all `is_numeric()` but
	 * none of them is a shape an id takes, and reading them as ids would send
	 * a genuinely non-numeric declaration down the id path where it could
	 * never match.
	 *
	 * @param string $token A normalised declaration token.
	 *
	 * @return boolean True when the token is an id, false when it is a slug.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function isIdToken(string $token): bool {
		return ctype_digit($token);
	}//end isIdToken()

	/**
	 * Total number of subscriptions declared in this process.
	 *
	 * The detectable invariant for the mechanism: an app that declares N
	 * subscriptions but whose guard fell back to unfiltered registration
	 * contributes 0 here, so a declared-versus-registered mismatch is a number
	 * a probe can read rather than an absence nobody can see. The proxy writes
	 * it into its trace line for the same reason.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function subscriptionCount(): int {
		self::scopeToRequest();

		$count = 0;
		foreach (self::$subscriptions as $entries) {
			$count += count($entries);
		}

		return $count;
	}//end subscriptionCount()

	/**
	 * Every subscribed listener class, keyed by the event it subscribes to.
	 *
	 * Companion to {@see self::subscriptionCount()}: the count says how many,
	 * this says which, so a missing app is identifiable and not merely implied
	 * by a smaller number.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function subscribedListeners(): array {
		self::scopeToRequest();

		$listeners = [];
		foreach (self::$subscriptions as $event => $entries) {
			foreach ($entries as $entry) {
				$listeners[$event][] = $entry['listener'];
			}
		}

		return $listeners;
	}//end subscribedListeners()

	/**
	 * Collect the distinct **slug** tokens of one declaration field.
	 *
	 * Id tokens are deliberately excluded. They are matched by direct
	 * comparison against the id the entity already carries, so including them
	 * would send a number to `findIdsBySlugs()` — a lookup that cannot match
	 * and that only widens the query the mechanism exists to keep bounded.
	 *
	 * Keys are cast back to string on the way out. PHP silently coerces a
	 * numeric-string array key to `int`, and every consumer of this list feeds
	 * it to `strtolower()`, which is a fatal `TypeError` on an `int` under
	 * `strict_types`. That coercion is what made an id-valued declaration
	 * return a 500 on every object write.
	 *
	 * @param string $field Either `registers` or `schemas`.
	 *
	 * @return array<int, string>
	 */
	private static function collect(string $field): array {
		$tokens = [];
		foreach (self::$subscriptions as $entries) {
			foreach ($entries as $entry) {
				if ($entry[$field] === null) {
					continue;
				}

				foreach ($entry[$field] as $token) {
					if (self::isIdToken(token: (string)$token) === true) {
						continue;
					}

					$tokens[$token] = true;
				}
			}
		}

		return array_map('strval', array_keys($tokens));
	}//end collect()

	/**
	 * Normalise a declaration list, collapsing an empty list to null.
	 *
	 * An empty array means "the app declared nothing", which must mean "all"
	 * rather than "nothing" — the opposite reading would silently disable a
	 * listener whose slug list came from an empty config value.
	 *
	 * Returns strings, always. The dedup runs through array keys, and PHP
	 * coerces the numeric-string key `'62'` to `int 62`, so `array_keys()`
	 * alone hands back a mixed `int|string` list; `strtolower()` on the `int`
	 * is then a fatal `TypeError` under `strict_types`. The `strval` map is
	 * what keeps an id-valued declaration a string all the way to the
	 * comparison.
	 *
	 * @param array<int,string>|null $tokens Raw declaration.
	 *
	 * @return array<int,string>|null
	 */
	private static function normalise(?array $tokens): ?array {
		if ($tokens === null) {
			return null;
		}

		$clean = [];
		foreach ($tokens as $token) {
			$token = trim((string)$token);
			if ($token !== '') {
				$clean[$token] = true;
			}
		}

		if ($clean === []) {
			return null;
		}

		return array_map('strval', array_keys($clean));
	}//end normalise()
}//end class
