<?php

/**
 * OpenRegister SheetHeading
 *
 * The heading at the top of a report sheet: title, optional subtitle, title font size.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

/**
 * The heading at the top of a report sheet: title, optional subtitle, title font size.
 */
final class SheetHeading {
	/**
	 * Constructor.
	 *
	 * @param string $title    Title text for A1.
	 * @param mixed  $subtitle Subtitle for A2; null or '' writes none.
	 * @param int    $fontSize Title font size.
	 */
	public function __construct(
		public readonly string $title,
		public readonly mixed $subtitle,
		public readonly int $fontSize,
	) {
	}//end __construct()
}//end class
