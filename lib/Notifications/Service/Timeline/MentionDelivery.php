<?php

/**
 * How a mention notification is sent and where its link points.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\IURLGenerator;
use OCP\Notification\IManager;

/**
 * How a mention notification is sent and where its link points.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final readonly class MentionDelivery {
	/**
	 * Bundle the services.
	 *
	 * @param IManager $notifications Sends the notification.
	 * @param DeepLinkRegistryServiceInterface $deepLinks Finds the app page that owns the object.
	 * @param IURLGenerator $urls Builds Open Register's own object page as the fallback link.
	 */
	public function __construct(
		public IManager $notifications,
		public DeepLinkRegistryServiceInterface $deepLinks,
		public IURLGenerator $urls,
	) {
	}//end __construct()
}//end class
