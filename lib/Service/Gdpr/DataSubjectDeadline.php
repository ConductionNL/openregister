<?php

/**
 * OpenRegister GDPR data-subject-request deadline helper.
 *
 * Pure, dependency-free implementation of the EU GDPR art-12(3) timing
 * for a data-subject request:
 *
 *   - the base response deadline is ONE month from the date the request
 *     was received;
 *   - the deadline MAY be extended ONCE by a further TWO months;
 *   - a deadline is overdue when the reference time is at or after the
 *     (possibly extended) deadline.
 *
 * This is the GENERIC mechanic — it carries no jurisdiction-specific
 * policy (no Dutch AVG wording, no AP / FG references). It is fully
 * unit-testable without a database or the Nextcloud runtime, so leaf
 * apps and OR's own DataSubjectRequestService can share the exact same
 * deadline maths.
 *
 * Month arithmetic uses DateInterval (`P1M` / `P2M`) so it follows the
 * civil-calendar convention (e.g. 31 Jan + 1 month = 28/29 Feb), which is
 * the conservative reading for a legal deadline.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * EU art-12(3) data-subject-request deadline maths.
 */
class DataSubjectDeadline
{

    /**
     * Base response term: one month from receipt (GDPR art-12(3)).
     *
     * @var string
     */
    public const BASE_TERM = 'P1M';

    /**
     * Single permitted extension: a further two months (art-12(3)).
     *
     * @var string
     */
    public const EXTENSION_TERM = 'P2M';

    /**
     * Compute the base due date: receivedAt + one month.
     *
     * @param DateTimeInterface $receivedAt When the request was received.
     *
     * @return DateTimeImmutable The base legal deadline.
     */
    public function computeDueAt(DateTimeInterface $receivedAt): DateTimeImmutable
    {
        return $this->toImmutable($receivedAt)->add(new DateInterval(self::BASE_TERM));

    }//end computeDueAt()

    /**
     * Extend the deadline once by two months.
     *
     * The extension is anchored on the supplied base due date (the result
     * of `computeDueAt`), NOT on "now", so a late-granted extension still
     * yields base + two months as the law intends. Callers MUST enforce
     * the "only once" rule by tracking whether an extension was already
     * granted (e.g. an `extendedUntil` field already set) — this helper
     * only performs the arithmetic.
     *
     * @param DateTimeInterface $dueAt The current base due date.
     *
     * @return DateTimeImmutable The extended deadline (base + 2 months).
     */
    public function extend(DateTimeInterface $dueAt): DateTimeImmutable
    {
        return $this->toImmutable($dueAt)->add(new DateInterval(self::EXTENSION_TERM));

    }//end extend()

    /**
     * Whether the deadline has passed relative to a reference time.
     *
     * @param DateTimeInterface      $deadline The (possibly extended) deadline.
     * @param DateTimeInterface|null $now      Reference time (defaults to now).
     *
     * @return bool True when the reference time is at or after the deadline.
     */
    public function isOverdue(DateTimeInterface $deadline, ?DateTimeInterface $now=null): bool
    {
        $reference = ($now ?? new DateTimeImmutable());
        return $this->toImmutable($reference) >= $this->toImmutable($deadline);

    }//end isOverdue()

    /**
     * Whole days remaining until the deadline (negative once breached).
     *
     * @param DateTimeInterface      $deadline The (possibly extended) deadline.
     * @param DateTimeInterface|null $now      Reference time (defaults to now).
     *
     * @return int Whole days left; negative when the deadline is in the past.
     */
    public function daysRemaining(DateTimeInterface $deadline, ?DateTimeInterface $now=null): int
    {
        $reference = $this->toImmutable($now ?? new DateTimeImmutable());
        $target    = $this->toImmutable($deadline);

        $diff = $reference->diff($target);
        $days = (int) $diff->days;
        if ($diff->invert === 1) {
            return ($days * -1);
        }

        return $days;

    }//end daysRemaining()

    /**
     * Normalise any DateTimeInterface to an immutable value.
     *
     * @param DateTimeInterface $value Input date.
     *
     * @return DateTimeImmutable
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface is a standard named constructor — no DI alternative.
     */
    private function toImmutable(DateTimeInterface $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        return DateTimeImmutable::createFromInterface($value);

    }//end toImmutable()
}//end class
