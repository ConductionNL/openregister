<?php

/**
 * OpenRegister webhook stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;

/**
 * The stores webhook delivery reads and writes: webhooks, their delivery
 * log, and the payload mappings (with the service that applies them).
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhookStores {
	/**
	 * Bundle the stores.
	 *
	 * @param WebhookMapperInterface    $webhookMapper    Webhook mapper
	 * @param WebhookLogMapperInterface $webhookLogMapper Webhook log mapper
	 * @param MappingMapperInterface    $mappingMapper    Mapping mapper
	 * @param MappingService            $mappingService   Mapping service
	 */
	public function __construct(
		public readonly WebhookMapperInterface $webhookMapper,
		public readonly WebhookLogMapperInterface $webhookLogMapper,
		public readonly MappingMapperInterface $mappingMapper,
		public readonly MappingService $mappingService,
	) {
	}//end __construct()
}//end class
