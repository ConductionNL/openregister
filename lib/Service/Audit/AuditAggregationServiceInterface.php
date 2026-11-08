<?php

/**
 * AuditAggregationServiceInterface: what callers outside the component use of AuditAggregationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTimeInterface;

/**
 * Declares only what callers outside the component use of AuditAggregationService; the rest
 * of the class stays its own.
 */
interface AuditAggregationServiceInterface {

	/**
	 * The longest window an administrator may set, in seconds.
	 *
	 * Equal to `AuditSealJob::INTERVAL_SECONDS`. See the class docblock for
	 * why that is the ceiling rather than a round number.
	 *
	 * @var integer
	 */
	public const MAXIMUM_WINDOW_SECONDS = 300;

	/**
	 * The administered window, in seconds. Zero means no merging.
	 *
	 * @return integer The window, clamped to the seal interval.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function windowSeconds(): int;

	/**
	 * Store the window, refusing anything outside the range.
	 *
	 * @param integer $seconds The window an administrator asked for.
	 *
	 * @return integer The window that was stored.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function setWindowSeconds(int $seconds): int;

	/**
	 * Whether two entries are close enough in time to be one.
	 *
	 * Takes both instants rather than reading the clock, so the behaviour is
	 * testable without waiting five minutes for the answer.
	 *
	 * @param DateTimeInterface|null $previous When the entry already written was written.
	 * @param DateTimeInterface $now When the edit being recorded happened.
	 * @param integer $window The administered window, in seconds.
	 *
	 * @return boolean Whether the two merge.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function isWithinWindow(?DateTimeInterface $previous, DateTimeInterface $now, int $window): bool;

	/**
	 * Fold an edit into the entry already written.
	 *
	 * The oldest `old` and the newest `new` survive per property, which is what
	 * makes the merged entry a true statement about the span it covers. The
	 * count is the point of the whole thing: a merged entry that does not say
	 * it merged is a lie of omission, and a reader has no way to tell three
	 * edits from one.
	 *
	 * @param array $previous The `changed` column of the entry already written.
	 * @param array $incoming The `changed` column of the edit being folded in.
	 *
	 * @return array The merged `changed` column.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function fold(array $previous, array $incoming): array;
}//end interface
