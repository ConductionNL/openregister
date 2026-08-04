<?php

/**
 * OpenRegister ChunkMatcherException.
 *
 * Thrown by `ChunkTextMatcher::match()` when the input cannot be
 * matched (value too long, malformed needle, etc.). The message MUST
 * NOT contain the operator-supplied needle (ADR-005 PII rule); only
 * structural / size information.
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
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Typed reason codes for `ChunkTextMatcher::match` failures.
 *
 * The class extends `\Exception` directly (not any Nextcloud-specific
 * exception) so generic `catch (NotPermittedException)` blocks in the
 * controller layer cannot accidentally absorb a matcher error.
 */
class ChunkMatcherException extends Exception
{
    /**
     * The needle is longer than the configured chunk overlap, so
     * per-chunk regex cannot reliably find matches that straddle a
     * boundary. The caller MUST reject the input.
     */
    public const REASON_VALUE_TOO_LONG = 'value_too_long';

    /**
     * The regex pattern compiled from the needle failed to compile.
     * Realistic trigger: malformed Unicode (lone surrogate, invalid
     * UTF-8 byte sequence) in the operator-supplied value.
     */
    public const REASON_REGEX_COMPILE_FAILURE = 'regex_compile_failure';

    /**
     * Constructor.
     *
     * @param string $reason  One of the `REASON_*` constants above.
     * @param string $message Human-readable description. MUST NOT contain the operator-supplied
     *                        needle per ADR-005 — only structural / size information.
     */
    public function __construct(
        private readonly string $reason,
        string $message=''
    ) {
        parent::__construct(message: $message);

    }//end __construct()

    /**
     * Get the typed reason code.
     *
     * @return string One of the `REASON_*` constants.
     */
    public function getReason(): string
    {
        return $this->reason;

    }//end getReason()
}//end class
