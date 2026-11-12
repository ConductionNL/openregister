<?php

/**
 * OpenRegister webhook log query
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

/**
 * The filters and page of one instance-wide webhook log listing.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhookLogQuery {
	/**
	 * The webhook to list, null for every webhook.
	 *
	 * @var integer|null
	 */
	public ?int $webhookId = null;

	/**
	 * The page size.
	 *
	 * @var integer
	 */
	public int $limit = 50;

	/**
	 * The page offset.
	 *
	 * @var integer
	 */
	public int $offset = 0;

	/**
	 * Only deliveries with this outcome, null for both.
	 *
	 * @var boolean|null
	 */
	public ?bool $success = null;
}//end class
