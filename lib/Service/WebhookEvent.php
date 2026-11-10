<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One event as webhooks receive it: the event class name and its payload.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhookEvent {
	/**
	 * Build the event.
	 *
	 * @param string $name    Event class name.
	 * @param array  $payload Event payload data.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class
