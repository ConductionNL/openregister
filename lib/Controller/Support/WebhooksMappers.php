<?php

/**
 * OpenRegister webhooks mappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Service\WebhookService;
use Psr\Log\LoggerInterface;

/**
 * Services of the webhooks API.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhooksMappers {
	/**
	 * Bundle the services.
	 *
	 * @param WebhookMapperInterface $webhookMapper    Webhook mapper
	 * @param WebhookLogMapperInterface       $webhookLogMapper Webhook log mapper
	 * @param WebhookService         $webhookService   Webhook service
	 * @param LoggerInterface        $logger           Logger
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function __construct(
		public readonly WebhookMapperInterface $webhookMapper,
		public readonly WebhookLogMapperInterface $webhookLogMapper,
		public readonly WebhookService $webhookService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
