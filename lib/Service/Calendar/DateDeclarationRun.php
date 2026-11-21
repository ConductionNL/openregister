<?php

/**
 * One stored date declaration while it is being read.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

/**
 * The property, kind and stored shape every reader of one date declaration
 * checks against.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class DateDeclarationRun {

	/**
	 * Constructor.
	 *
	 * @param string $property The property being declared.
	 * @param string $kind The declared kind.
	 * @param array<string, mixed> $config The declaration as stored.
	 *
	 * @return void
	 */
	public function __construct(
		public string $property,
		public string $kind,
		public array $config,
	) {

	}//end __construct()
}//end class
