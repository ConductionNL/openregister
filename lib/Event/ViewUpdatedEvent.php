<?php

/**
 * OpenRegister ViewUpdatedEvent
 *
 * This file contains the event class dispatched when a view is updated
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
 * Event dispatched when a view is updated.
 */
class ViewUpdatedEvent extends Event {

	/**
	 * The updated view state.
	 *
	 * @var View The view after update.
	 */
	private View $newView;

	/**
	 * The previous view state.
	 *
	 * @var View The view before update.
	 */
	private View $oldView;

	/**
	 * Constructor for ViewUpdatedEvent.
	 *
	 * @param View $newView The view after update.
	 * @param View $oldView The view before update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(View $newView, View $oldView) {
		parent::__construct();
		$this->newView = $newView;
		$this->oldView = $oldView;
	}//end __construct()

	/**
	 * Get the updated view
	 *
	 * The listener that turns this event into a webhook payload calls
	 * getView(). The class carried no accessor at all, so that call was a
	 * fatal error on every dispatch; the only test covering it doubled the
	 * event with addMethods(['getView']), which invents the method on the
	 * mock and never consults the real class.
	 *
	 * @return View The view after update
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getView(): View {
		return $this->newView;
	}//end getView()

	/**
	 * Get the updated view
	 *
	 * @return View The view after update
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getNewView(): View {
		return $this->newView;
	}//end getNewView()

	/**
	 * Get the original view
	 *
	 * @return View The view before update
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getOldView(): View {
		return $this->oldView;
	}//end getOldView()
}//end class
