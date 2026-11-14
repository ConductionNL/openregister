<?php

/**
 * ArchiveWalk
 *
 * The running totals of one archive enumeration (REQ-AMS-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Members and uncompressed bytes seen so far, across nested archives.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */
final class ArchiveWalk {

	/**
	 * The members seen so far.
	 *
	 * @var int
	 */
	public int $members = 0;

	/**
	 * The uncompressed bytes counted so far.
	 *
	 * @var int
	 */
	public int $bytes = 0;

	/**
	 * Constructor.
	 *
	 * @param ArchiveBounds  $bounds  The bounds.
	 * @param ArchiveListing $listing The listing being filled.
	 */
	public function __construct(
		public readonly ArchiveBounds $bounds,
		public readonly ArchiveListing $listing,
	) {
	}//end __construct()

	/**
	 * The bound a member exceeds, counting its bytes when it exceeds none.
	 *
	 * A member that expands from nothing, or by more than the ratio, exceeds
	 * `maxRatio`; one that takes the total past it exceeds `maxTotalBytes`.
	 *
	 * @param int $size       The uncompressed size.
	 * @param int $compressed The compressed size.
	 *
	 * @return string|null The bound's name, or null.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function exceeds(int $size, int $compressed): ?string {
		if ($size > 0 && ($compressed <= 0 || $size > $compressed * $this->bounds->maxRatio)) {
			return 'maxRatio';
		}

		if ($this->bytes + $size > $this->bounds->maxTotalBytes) {
			return 'maxTotalBytes';
		}

		$this->bytes += $size;
		return null;
	}//end exceeds()
}//end class
