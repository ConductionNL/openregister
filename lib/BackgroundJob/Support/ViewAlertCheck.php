<?php

/**
 * One view's alert check within a ViewAlertSweepJob pass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use DateTime;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\IUser;

/**
 * The values the steps of one view's alert check share: the view and the pass
 * clock, then the due alert, the owner the count is taken as, and the count.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
 */
final class ViewAlertCheck {

	/**
	 * The view's alert, once it is known to be due.
	 *
	 * @var ViewAlert|null
	 */
	public ?ViewAlert $alert = null;

	/**
	 * The view's owner, once resolved; the count is taken with their rights.
	 *
	 * @var IUser|null
	 */
	public ?IUser $owner = null;

	/**
	 * The count of the view's query as its owner.
	 *
	 * @var integer
	 */
	public int $count = 0;

	/**
	 * Constructor.
	 *
	 * @param View     $view The view whose alert is checked.
	 * @param DateTime $now  The pass clock.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
	 */
	public function __construct(
		public readonly View $view,
		public readonly DateTime $now,
	) {
	}//end __construct()
}//end class
