<?php

/**
 * DateTime Normalizer
 *
 * Canonical conversion point for user-supplied datetime input. Guarantees that
 * `null`, the empty string, and whitespace-only strings become `null` rather
 * than being silently interpreted as "now" by PHP's `new DateTime('')`.
 *
 * All OpenRegister code paths that convert user-supplied datetime values to
 * `DateTime`/`DateTimeImmutable` (or to a database datetime string) MUST
 * delegate to this class. Direct use of `new DateTime($value)` on user data
 * is forbidden — see OpenSpec change `fix-empty-string-date-conversion`.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Introduced by fix-empty-string-date-conversion
 *
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-24
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Normalises user-supplied datetime input to `DateTimeImmutable` or formatted
 * strings, treating empty/whitespace/null input as absence (`null`).
 *
 * Rules enforced by `normalize()`:
 *   1. `null`                       → `null`
 *   2. `string` trimmed and empty   → `null`
 *   3. `DateTimeInterface` instance → `DateTimeImmutable` of the same instant
 *   4. parse failure / unsupported type → `null` (with debug log)
 */
class DateTimeNormalizer
{

    /**
     * MySQL/MariaDB datetime format.
     *
     * @var string
     */
    public const DATABASE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger for debug-level notices on parse failures.
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Normalise user-supplied input to `DateTimeImmutable` or `null`.
     *
     * @param mixed $value Value to normalise (string, null, DateTimeInterface, or anything else).
     *
     * @return DateTimeImmutable|null A `DateTimeImmutable` when parseable, otherwise `null`.
     */
    public function normalize(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable === true) {
            return $value;
        }

        if ($value instanceof DateTimeInterface === true) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) === false) {
            $this->logger->debug(
                message: '[DateTimeNormalizer] Non-string, non-DateTime input rejected',
                context: ['file' => __FILE__, 'line' => __LINE__, 'type' => get_debug_type($value)]
            );
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($trimmed);
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[DateTimeNormalizer] Unparseable datetime string',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'value' => $trimmed,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end normalize()

    /**
     * Format user-supplied input as a database datetime string.
     *
     * @param mixed $value Value to normalise and format.
     *
     * @return string|null `Y-m-d H:i:s`-formatted string, or `null` for empty/invalid input.
     */
    public function formatForDatabase(mixed $value): ?string
    {
        $dt = $this->normalize(value: $value);
        if ($dt === null) {
            return null;
        }

        return $dt->format(self::DATABASE_FORMAT);
    }//end formatForDatabase()

    /**
     * Format user-supplied input as an ISO 8601 string with timezone offset.
     *
     * @param mixed $value Value to normalise and format.
     *
     * @return string|null ISO 8601 string with offset, or `null` for empty/invalid input.
     */
    public function formatForIso8601(mixed $value): ?string
    {
        $dt = $this->normalize(value: $value);
        if ($dt === null) {
            return null;
        }

        return $dt->format(DateTimeInterface::ATOM);
    }//end formatForIso8601()
}//end class
