<?php

/**
 * The services of the deprecated notification subscription API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper;

/**
 * The stores a subscription and its permission check read.
 */
final readonly class NotificationSubscriptionsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param NotificationSubscriptionMapper $mapper         Subscription mapper.
	 * @param RegisterMapperInterface        $registerMapper Register mapper for permission check.
	 * @param SchemaMapperInterface          $schemaMapper   Schema mapper for permission check.
	 */
	public function __construct(
		public NotificationSubscriptionMapper $mapper,
		public RegisterMapperInterface $registerMapper,
		public SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
