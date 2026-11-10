<?php

/**
 * OpenRegister webhook delivery stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;

/**
 * The two tables a webhook delivery is retried from: the webhooks and their delivery logs.
 *
 * Wired by DI autowiring.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhookDeliveryStores {
	/**
	 * Bundle the mappers.
	 *
	 * @param WebhookMapperInterface    $webhookMapper    The webhooks.
	 * @param WebhookLogMapperInterface $webhookLogMapper Their delivery logs.
	 */
	public function __construct(
		public readonly WebhookMapperInterface $webhookMapper,
		public readonly WebhookLogMapperInterface $webhookLogMapper,
	) {
	}//end __construct()
}//end class
