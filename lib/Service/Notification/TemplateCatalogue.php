<?php

/**
 * The event inventory and the texts a notification template registry answers about.
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
 * An event inventory and its shipped texts; null for either means the platform's own.
 *
 * Lets a test declare an event nobody wrote a template for and watch the gap
 * list name it.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
 */
final class TemplateCatalogue {

	/**
	 * Constructor.
	 *
	 * @param array<string, array{group: string, variables: array<string, string>}>|null $events  The inventory, or null for the platform's own.
	 * @param array<string, array<string, array{subject: string, body: string}>>|null    $shipped The texts, or null for the shipped ones.
	 */
	public function __construct(
		public readonly ?array $events = null,
		public readonly ?array $shipped = null,
	) {
	}//end __construct()
}//end class
