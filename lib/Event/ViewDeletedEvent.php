<?php

/**
 * OpenRegister ViewDeletedEvent
 *
 * This file contains the event class dispatched when a view is deleted
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\View;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a view is deleted.
 */
class ViewDeletedEvent extends Event {

	/**
	 * The deleted view.
	 *
	 * @var View The view that was deleted.
	 */
	private View $view;

	/**
	 * Constructor for ViewDeletedEvent.
	 *
	 * @param View $view The view that was deleted.
	 *
	 * @return void
	 */
	public function __construct(View $view) {
		parent::__construct();
		$this->view = $view;
	}//end __construct()

	/**
	 * Get the deleted view.
	 *
	 * @return View The view that was deleted.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getView(): View {
		return $this->view;
	}//end getView()
}//end class
