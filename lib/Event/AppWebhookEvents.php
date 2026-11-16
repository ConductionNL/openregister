<?php

/**
 * App-declared webhook events.
 *
 * Lets another app declare one of its own event classes as deliverable to
 * OpenRegister's webhooks, with the method that returns the payload and the
 * name, description and category shown in the event catalogue.
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
 *
 * @spec openspec/changes/connect-document-webhooks/specs/app-declared-webhook-events/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Listener\WebhookEventListener;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use ReflectionMethod;

/**
 * Registry of event classes other apps hand to OpenRegister's webhooks.
 *
 * Usage from a leaf app's `Application::boot()`:
 *
 * ```php
 * \OCA\OpenRegister\Event\AppWebhookEvents::declare(
 *     dispatcher:  $dispatcher,
 *     event:       DocumentSignedEvent::class,
 *     name:        'Document signed',
 *     description: 'A signing request completed and the signed file is stored',
 *     category:    'Filinq',
 *     payload:     'getPayload',
 * );
 * ```
 *
 * The app's event class needs no OpenRegister type: the declaration names a
 * public, argument-free method returning the payload array. OpenRegister's
 * webhook listener is subscribed to the class, so a webhook naming that class
 * (or an `fnmatch` pattern over it) receives that payload as it is.
 *
 * A declaration is refused, and nothing is subscribed, when the class does not
 * exist, is not a Nextcloud event, lives in OpenRegister's own namespace (it
 * would shadow a built-in event), or has no such payload method.
 *
 * All state is per-request, like {@see ObjectEventSubscription}: apps declare
 * again from `boot()` on every request.
 *
 * @spec openspec/changes/connect-document-webhooks/specs/app-declared-webhook-events/spec.md
 */
final class AppWebhookEvents {

	/**
	 * Namespace an app event may not live in.
	 *
	 * @var string
	 */
	private const RESERVED_NAMESPACE = 'OCA\\OpenRegister\\';

	/**
	 * Declared events, keyed by event class name.
	 *
	 * @var array<string, array{class: string, name: string, description: string, category: string, payload: string}>
	 */
	private static array $declared = [];

	/**
	 * Token identifying the request the current state belongs to.
	 *
	 * @var string|null
	 */
	private static ?string $requestToken = null;

	/**
	 * Discard state left over from a previous request in this worker.
	 *
	 * @return void
	 */
	private static function scopeToRequest(): void {
		$token = (string)($_SERVER['REQUEST_TIME_FLOAT'] ?? 'no-request');
		if (self::$requestToken === $token) {
			return;
		}

		self::$requestToken = $token;
		self::$declared = [];

	}//end scopeToRequest()

	/**
	 * Declare an app event as deliverable to webhooks.
	 *
	 * Declaring the same class twice in one request keeps the first
	 * declaration and subscribes the listener once.
	 *
	 * @param IEventDispatcher $dispatcher  The live event dispatcher.
	 * @param string           $event       The app's event class name.
	 * @param string           $name        Name shown in the event catalogue.
	 * @param string           $description Description shown in the event catalogue.
	 * @param string           $category    Category shown in the event catalogue.
	 * @param string           $payload     Public, argument-free method of the event returning the payload array.
	 *
	 * @return bool True when the event is declared, false when the declaration was refused.
	 *
	 * @spec openspec/changes/connect-document-webhooks/specs/app-declared-webhook-events/spec.md#requirement-an-app-declares-its-own-event-for-webhook-delivery
	 */
	public static function declare(
		IEventDispatcher $dispatcher,
		string $event,
		string $name,
		string $description,
		string $category,
		string $payload='getPayload',
	): bool {
		self::scopeToRequest();

		if (isset(self::$declared[$event]) === true) {
			return true;
		}

		if (self::isDeclarable(event: $event, payload: $payload) === false) {
			return false;
		}

		$dispatcher->addServiceListener($event, WebhookEventListener::class);
		self::$declared[$event] = [
			'class' => $event,
			'name' => $name,
			'description' => $description,
			'category' => $category,
			'payload' => $payload,
		];

		return true;

	}//end declare()

	/**
	 * Whether a class may be declared with that payload method.
	 *
	 * @param string $event   The event class name.
	 * @param string $payload The payload method name.
	 *
	 * @return bool True when the class is an app's event with a usable payload method.
	 */
	private static function isDeclarable(string $event, string $payload): bool {
		if (str_starts_with(ltrim($event, '\\'), self::RESERVED_NAMESPACE) === true) {
			return false;
		}

		if (class_exists($event) === false || is_subclass_of($event, Event::class) === false) {
			return false;
		}

		if (method_exists($event, $payload) === false) {
			return false;
		}

		$method = new ReflectionMethod($event, $payload);

		return $method->isPublic() === true
			&& $method->isStatic() === false
			&& $method->getNumberOfRequiredParameters() === 0;

	}//end isDeclarable()

	/**
	 * The payload of a declared app event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null The payload, or null when the event's class is not declared
	 *                                   or its payload method returns no array.
	 *
	 * @spec openspec/changes/connect-document-webhooks/specs/app-declared-webhook-events/spec.md#requirement-a-declared-event-is-delivered-with-its-own-payload
	 */
	public static function payloadOf(Event $event): ?array {
		self::scopeToRequest();

		$declaration = (self::$declared[get_class($event)] ?? null);
		if ($declaration === null) {
			return null;
		}

		$payload = $event->{$declaration['payload']}();
		if (is_array($payload) === false) {
			return null;
		}

		return $payload;

	}//end payloadOf()

	/**
	 * The declared events as event catalogue entries, in declaration order.
	 *
	 * @return list<array{class: string, name: string, description: string, category: string, type: string, properties: list<string>}>
	 *
	 * @spec openspec/changes/connect-document-webhooks/specs/app-declared-webhook-events/spec.md#requirement-the-event-catalogue-lists-declared-events
	 */
	public static function catalogue(): array {
		self::scopeToRequest();

		$entries = [];
		foreach (self::$declared as $declaration) {
			$entries[] = [
				'class' => $declaration['class'],
				'name' => $declaration['name'],
				'description' => $declaration['description'],
				'category' => $declaration['category'],
				'type' => 'after',
				'properties' => ['payload'],
			];
		}

		return $entries;

	}//end catalogue()
}//end class
