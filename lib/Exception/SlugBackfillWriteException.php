<?php

/**
 * OpenRegister SlugBackfillWriteException.
 *
 * Thrown when a write chunk of the slug backfill fails, after the chunks
 * before it were committed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;
use Throwable;

/**
 * A write chunk of the slug backfill failed.
 *
 * The failing chunk was rolled back; the chunks before it stay written, their
 * cache entries are evicted and the audit entry lists exactly them. The count
 * tells the caller how far the run got.
 */
class SlugBackfillWriteException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int       $applied  Slugs written before the failure.
	 * @param int       $skipped  Planned writes skipped because the row changed.
	 * @param Throwable $previous The failure of the chunk.
	 */
	public function __construct(
		private readonly int $applied,
		private readonly int $skipped,
		Throwable $previous,
	) {
		parent::__construct(
			message: sprintf(
				'The slug backfill stopped after %d written and %d skipped slug(s): %s',
				$applied,
				$skipped,
				$previous->getMessage()
			),
			previous: $previous
		);
	}//end __construct()

	/**
	 * Slugs written (and kept) before the failure.
	 *
	 * @return int The count.
	 */
	public function getApplied(): int {
		return $this->applied;
	}//end getApplied()

	/**
	 * Planned writes skipped before the failure because the row changed.
	 *
	 * @return int The count.
	 */
	public function getSkipped(): int {
		return $this->skipped;
	}//end getSkipped()
}//end class
