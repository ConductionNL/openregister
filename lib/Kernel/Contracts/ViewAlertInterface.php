<?php
/**
 * ViewAlertInterface: what a listener reads of a view's count alert.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The count alert of one saved view: its line, who hears about a crossing and how.
 *
 * Implemented by \OCA\OpenRegister\Service\View\ViewAlert. ViewAlertCrossedEvent
 * carries this read interface instead of the Core value class (events name
 * Kernel types only).
 */
interface ViewAlertInterface {

	/**
	 * The direction of the line: `gte` or `lte`.
	 *
	 * @return string The operator.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function getOperator(): string;

	/**
	 * The count the line sits at.
	 *
	 * @return int The threshold.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function getThreshold(): int;

	/**
	 * Who hears about a crossing: `user:<uid>` entries and group ids.
	 *
	 * @return string[] The recipients.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function getRecipients(): array;

	/**
	 * The channels a crossing is delivered over.
	 *
	 * @return string[] The channels.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function getChannels(): array;
}//end interface
