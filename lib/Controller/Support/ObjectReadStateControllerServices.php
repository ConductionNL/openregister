<?php

/**
 * OpenRegister object read state controller services
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

use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingServiceInterface;
use OCA\OpenRegister\Service\Interaction\ReadStateServiceInterface;

/**
 * The read-state store and the notification clearing a read marks.
 *
 * @spec openspec/specs/object-read-state/spec.md
 */
final readonly class ObjectReadStateControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ReadStateServiceInterface            $readState Read-state store.
	 * @param NotificationClearingServiceInterface $clearing  Clears the notifications a read covers.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function __construct(
		public ReadStateServiceInterface $readState,
		public NotificationClearingServiceInterface $clearing,
	) {
	}//end __construct()
}//end class
