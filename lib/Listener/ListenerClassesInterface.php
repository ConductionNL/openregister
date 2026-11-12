<?php

/**
 * ListenerClassesInterface: the listener classes callers outside Listener register by name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

/**
 * The class names of listeners that code outside this component registers with
 * the event dispatcher. Each constant is the listener's own ::class string, so the
 * dispatcher receives exactly the same service name as before and resolves the
 * same listener; the listener class itself stays inside the Listener component.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
interface ListenerClassesInterface {

	/**
	 * The proxy ObjectEventSubscription registers for filtered object-event subscriptions.
	 *
	 * @var class-string<ObjectEventProxyListener>
	 */
	public const OBJECT_EVENT_PROXY = ObjectEventProxyListener::class;
}//end interface
