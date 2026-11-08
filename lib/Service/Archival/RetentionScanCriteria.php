<?php

/**
 * Which scanned objects a retention sweep keeps, and how many more it may keep.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The match predicate of one retention scan and the matches it may still collect under the run's cap.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class RetentionScanCriteria {
	/**
	 * Hold the criteria.
	 *
	 * @param Closure $accept    Predicate answering whether one object is a match.
	 * @param int     $remaining How many more matches this run may still collect.
	 *
	 * @psalm-param Closure(ObjectEntity): bool $accept
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly Closure $accept,
		public readonly int $remaining,
	) {
	}//end __construct()

	/**
	 * Whether one object is a match.
	 *
	 * @param ObjectEntity $object The hydrated object.
	 *
	 * @return bool False only when the predicate answered false, as the scan has always read it.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function accepts(ObjectEntity $object): bool {
		return (($this->accept)($object) !== false);
	}//end accepts()
}//end class
