<?php

/**
 * One request to render an event's notification text.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * The event to render, the reader's locale and the values for the template's variables.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
 */
final class TemplateRendering {

	/**
	 * Constructor.
	 *
	 * @param string               $event     The event name.
	 * @param string|null          $locale    The reader's locale, or null for the default.
	 * @param array<string, mixed> $variables Values for the template's variables.
	 */
	public function __construct(
		public readonly string $event,
		public readonly ?string $locale,
		public readonly array $variables = [],
	) {
	}//end __construct()
}//end class
