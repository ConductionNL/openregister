<?php

/**
 * OpenRegister CloudEventAttributes
 *
 * The context attributes a webhook names for one CloudEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Webhook;

/**
 * The event type, its source and its subject; a null source means the app itself.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class CloudEventAttributes {
	/**
	 * Constructor.
	 *
	 * @param string      $type    Event type (e.g. 'object.created', 'object.updated').
	 * @param string|null $source  Event source; null defaults to '/apps/openregister'.
	 * @param string|null $subject Event subject, when there is one.
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $source = null,
		public readonly ?string $subject = null,
	) {
	}//end __construct()
}//end class
