<?php

/**
 * NotificationTemplatesInterface: lists, renders and edits the notification templates.
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

/**
 * Lists, renders and edits the notification templates. Implemented by Service\Notification\NotificationTemplateRegistry.
 */
interface NotificationTemplatesInterface {

	/**
	 * Record or clear an administrator's edit of one event's template.
	 *
	 * @param string $event The event name.
	 * @param array<string, array{subject: string, body: string}>|null $template The per-locale text, or null to restore what shipped.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the event is not one the platform raises.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function edit(string $event, ?array $template): void;

	/**
	 * The events that have no template at all.
	 *
	 * @return array<int, string> The event names, in inventory order.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function gaps(): array;

	/**
	 * Whether an administrator has changed this event's words.
	 *
	 * Distinct from "has a template": every platform event ships one, and the
	 * question a renderer needs answered is whether somebody replaced it.
	 *
	 * @param string $event The event name.
	 *
	 * @return boolean True when an edit is stored for this event.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function hasEdit(string $event): bool;

	/**
	 * Whether the platform declares this event at all.
	 *
	 * @param string $event The event name.
	 *
	 * @return boolean True when the event is in the inventory.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function knows(string $event): bool;

	/**
	 * Every event, with its template, its variables and where the text came from.
	 *
	 * @return array<int, array<string, mixed>> One entry per event, event name first.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function listAll(): array;

	/**
	 * Render one event's subject and body in a locale, or nothing.
	 *
	 * Returns null rather than a generic line when the event has no template.
	 * A caller that gets null must say so or fall back to something it wrote
	 * itself; what it must never do is print a sentence that reads like the
	 * template somebody forgot to write, because then nobody finds out.
	 *
	 * @param string $event The event name.
	 * @param string|null $locale The reader's locale, or null for the default.
	 * @param array<string, mixed> $variables Values for the template's variables.
	 *
	 * @return array{subject: string, body: string}|null The rendered text, or null when the event has no template.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function render(string $event, ?string $locale, array $variables = []): ?array;

	/**
	 * The template that applies for one event, the edit winning over the shipped text.
	 *
	 * @param string $event The event name.
	 *
	 * @return array<string, array{subject: string, body: string}>|null The per-locale template, or null when there is none.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function templateFor(string $event): ?array;
}//end interface
