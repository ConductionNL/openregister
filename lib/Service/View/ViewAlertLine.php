<?php

/**
 * The line a view's count alert watches: a direction and a threshold.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\View
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\View;

/**
 * Which way a count must go (one of ViewAlert::OPERATORS) and how far, as
 * {@see ViewAlert::parse()} validated them.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */
final class ViewAlertLine {

	/**
	 * Constructor.
	 *
	 * @param string $operator  One of ViewAlert::OPERATORS.
	 * @param int    $threshold The line.
	 */
	public function __construct(
		public readonly string $operator,
		public readonly int $threshold,
	) {
	}//end __construct()
}//end class
