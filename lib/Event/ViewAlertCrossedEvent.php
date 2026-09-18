<?php

/**
 * A saved view's count crossed the line its owner drew.
 *
 * 🔴 IT IS AN EVENT, NOT A NOTIFICATION, and that is a limit rather than a
 * design flourish. The notification senders in this app are object-shaped:
 * every one of them takes an ObjectEntity and builds a deeplink from its
 * register, schema and uuid. A view alert is about a NUMBER — there is no
 * object it is about — and inventing one to satisfy the signature would put a
 * fabricated record in the link the notification tells somebody to click.
 *
 * So the sweep says what happened, once per crossing, and the leg that turns
 * that into a message a person receives is not built here. What this is NOT is
 * a hook that looks done: the event is dispatched, carries everything a sender
 * would need, and the sweep is tested on it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\EventDispatcher\Event;

/**
 * Dispatched once each time a view's count crosses its threshold.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */
class ViewAlertCrossedEvent extends Event {

	/**
	 * The source name a listener filters on.
	 *
	 * @var string
	 */
	public const SOURCE = 'view-alert';

	/**
	 * Constructor.
	 *
	 * @param View      $view  The view whose count crossed.
	 * @param ViewAlert $alert The declaration it crossed.
	 * @param int       $count The count that crossed it.
	 */
	public function __construct(
		private readonly View $view,
		private readonly ViewAlert $alert,
		private readonly int $count,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The view.
	 *
	 * @return View The view.
	 */
	public function getView(): View {
		return $this->view;
	}//end getView()

	/**
	 * The alert declaration.
	 *
	 * @return ViewAlert The alert.
	 */
	public function getAlert(): ViewAlert {
		return $this->alert;
	}//end getAlert()

	/**
	 * The count that crossed the threshold.
	 *
	 * @return int The count.
	 */
	public function getCount(): int {
		return $this->count;
	}//end getCount()
}//end class
