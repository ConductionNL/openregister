<?php

/**
 * OpenRegister ViewAlertDeliveryRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

/**
 * One view-alert crossing being delivered to its readable recipients.
 *
 * Built by {@see ViewAlertCrossedListener} once the recipients resolved;
 * it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */
final class ViewAlertDeliveryRun {

	/**
	 * The recipients who may still read the view.
	 *
	 * @var array<int,string>
	 */
	public array $uids = [];

	/**
	 * The subject parameters of the crossing: view, viewId, count, operator and threshold.
	 *
	 * @var array<string,mixed>
	 */
	public array $parameters = [];
}//end class
