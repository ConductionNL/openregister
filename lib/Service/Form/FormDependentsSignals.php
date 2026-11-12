<?php

/**
 * OpenRegister FormDependentsSignals
 *
 * The event and notification channels the form dependents check reports through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Notification\IManager;

/**
 * The event and notification channels the form dependents check reports through.
 *
 * Groups collaborators of {@see FormDependentsCheck} so its constructor stays small;
 * the container autowires it.
 */
final class FormDependentsSignals {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IEventDispatcher $dispatcher    Asks the owning apps for their forms.
	 * @param IManager         $notifications Tells an author their form was unpublished.
	 */
	public function __construct(
		public readonly IEventDispatcher $dispatcher,
		public readonly IManager $notifications,
	) {
	}//end __construct()
}//end class
