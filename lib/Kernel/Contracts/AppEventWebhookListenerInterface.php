<?php
/**
 * AppEventWebhookListenerInterface: the listener AppWebhookEvents subscribes to an app-declared event.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Delivers an app-declared event to the webhooks that name it.
 *
 * AppWebhookEvents::declare() subscribes this interface, by its service name,
 * to the declared event class. The area that delivers webhooks binds it to
 * its listener in its own registrar, so the Kernel declaration names no class
 * of that area.
 *
 * @template-extends IEventListener<Event>
 *
 * @spec openspec/changes/connect-document-webhooks/specs/app-declared-webhook-events/spec.md
 */
interface AppEventWebhookListenerInterface extends IEventListener {
}//end interface
