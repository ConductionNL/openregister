<?php

/**
 * The stretch of time a lapse warning looks across.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * From a moment, a number of days ahead.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
final class LapseWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface $now  The moment the window opens.
	 * @param int               $days How far ahead it looks.
	 */
	public function __construct(
		public readonly DateTimeInterface $now,
		public readonly int $days,
	) {
	}//end __construct()

	/**
	 * The last timestamp inside the window.
	 *
	 * @return int The horizon, as a Unix timestamp.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function horizon(): int {
		return ($this->now->getTimestamp() + ($this->days * 86400));
	}//end horizon()
}//end class
