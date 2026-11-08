<?php

/**
 * OpenRegister view alert sweep sources
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\View\ViewObjectQueryInterface;

/**
 * The saved views an alert sweep walks and the object query that counts each view.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
 */
final readonly class ViewAlertSweepSources {
	/**
	 * Bundle the services.
	 *
	 * @param ViewMapperInterface       $views     Saved views with an alert.
	 * @param ObjectOperationsInterface $objects   Object counting.
	 * @param ViewObjectQueryInterface  $viewQuery Turns a view into its object query.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
	 */
	public function __construct(
		public ViewMapperInterface $views,
		public ObjectOperationsInterface $objects,
		public ViewObjectQueryInterface $viewQuery,
	) {
	}//end __construct()
}//end class
