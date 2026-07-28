<?php

/**
 * ISO 8601 Date-Time Format Validator
 *
 * Validates ISO 8601 date-time strings with optional seconds and optional
 * timezone, relaxing the opis/json-schema built-in `date-time` format whose
 * regex mandates seconds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * ISO 8601 date-time format validator (relaxed)
 *
 * Accepts ISO 8601 date-time values where the seconds component and/or the
 * timezone designator are optional. Replaces the opis built-in `date-time`
 * format, which mandates seconds, so company-standard ISO 8601 input (e.g.
 * a `datetime-local` value without seconds) is accepted by the API.
 *
 * Examples of valid values:
 * - 2026-06-18T10:14
 * - 2026-06-18T10:14:00
 * - 2026-06-18T10:14:00Z
 * - 2026-06-18T10:14:00+02:00
 * - 2026-06-18T10:14:00.123Z
 *
 * Examples of invalid values:
 * - 2026-06-18        (date only, no time)
 * - 2026-13-01T10:14  (month out of range)
 * - 2026-02-30T10:14  (impossible calendar date)
 * - tomorrow          (relative string)
 * - not-a-date
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
 * @author   Conduction AI <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.conduction.nl
 */
class Iso8601DateTimeFormat implements Format
{
    /**
     * Relaxed ISO 8601 date-time pattern.
     *
     * Derived from the opis/json-schema built-in date-time regex with the
     * seconds component made optional (alongside the already-optional
     * timezone). Capture groups 1-3 (year, month, day) are reused for a
     * calendar-validity check via checkdate().
     *
     * @var string
     */
    private const ISO8601_DATETIME_PATTERN = '/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])'
        .'T([01][0-9]|2[0-3]):([0-5][0-9])(:([0-5][0-9]|60)(\.[0-9]+)?)?'
        .'(Z|(\+|-)([01][0-9]|2[0-3]):([0-5][0-9]))?$/i';

    /**
     * Validates if a given value is an ISO 8601 date-time with optional seconds/timezone
     *
     * @param mixed $data The data to validate against the date-time format
     *
     * @inheritDoc
     *
     * @return bool True if data is a valid ISO 8601 date-time, false otherwise
     *
     * @spec openspec/specs/data-import-export/spec.md
     */
    public function validate(mixed $data): bool
    {
        // Only validate strings.
        if (is_string($data) === false) {
            return false;
        }

        // Match the relaxed pattern and capture the date components.
        if (preg_match(self::ISO8601_DATETIME_PATTERN, $data, $matches) !== 1) {
            return false;
        }

        // Reject impossible calendar dates (e.g. 2026-02-30).
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }//end validate()
}//end class
