<?php

/**
 * Who a view alert reaches: the resolved recipients, re-checked against who may still read the view.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolverInterface;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolverInterface;

/**
 * Who a view alert reaches: the resolved recipients, re-checked against who may still read the view.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */
final readonly class ViewAlertAudience {
	/**
	 * Bundle the services.
	 *
	 * @param NotificationRecipientResolverInterface $recipients The engine's recipient resolver.
	 * @param ViewerReachResolverInterface $viewers Re-checks at send time that a recipient may still read the view.
	 */
	public function __construct(
		public NotificationRecipientResolverInterface $recipients,
		public ViewerReachResolverInterface $viewers,
	) {
	}//end __construct()
}//end class
