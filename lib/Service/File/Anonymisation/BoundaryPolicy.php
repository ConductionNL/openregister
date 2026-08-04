<?php

/**
 * BoundaryPolicy
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Anonymisation;

use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;

/**
 * Decides, per entity type, whether a candidate match is allowed at a position.
 *
 * The split is by **numeric embeddability**, not by "structured vs free-text".
 * A short numeric needle inside a longer number does not merely over-redact —
 * it silently rewrites a DIFFERENT value:
 *
 *     needle 192.168.1.1 inside 192.168.1.10  ->  [IP-ADRES: 1]0
 *     needle 123456789   inside 1234567890    ->  [BSN: 7]0
 *
 * Two adjacent IP addresses where one is a prefix of the other is an everyday
 * occurrence in logs, so literal matching was actively wrong for those types.
 * Only EMAIL and IBAN stay literal: long and alphanumeric enough that substring
 * false positives are negligible, which buys tolerance for unseparated forms
 * (`IBANNL91ABNA0417164300`) where any boundary rule would reject a real match.
 *
 * Unenumerated types default to WORD-BOUNDED, not literal. A boundary miss is
 * reported as `unmatched`; a literal false positive is silent. Prefer the
 * policy whose failures are observable.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class BoundaryPolicy
{

    /**
     * No adjacent word codepoint (letter, mark, digit, underscore).
     *
     * @var string
     */
    public const POLICY_BOUNDED = 'bounded';

    /**
     * Bounded, and not a proper substring of a longer numeric token.
     *
     * @var string
     */
    public const POLICY_DELIMITED_TOKEN = 'delimited-token';

    /**
     * No boundary requirement at all.
     *
     * @var string
     */
    public const POLICY_LITERAL = 'literal';

    /**
     * Separators that join digits into one numeric token, but only when the
     * separator is itself immediately followed by a digit. That proviso is what
     * lets a sentence-final `1980.` match while `2026-0012` does not.
     *
     * @var array<int, string>
     */
    private const NUMERIC_SEPARATORS = ['-', '/', '.', ':'];

    /**
     * Resolve the policy for an entity type.
     *
     * @param string $entityType The canonical entity type, e.g. `PERSON`.
     *
     * @return string One of the POLICY_* constants.
     */
    public function forType(string $entityType): string
    {
        $literal = [
            EntityRecognitionHandler::ENTITY_TYPE_EMAIL,
            EntityRecognitionHandler::ENTITY_TYPE_IBAN,
        ];

        if (in_array($entityType, $literal, true) === true) {
            return self::POLICY_LITERAL;
        }

        $delimited = [
            EntityRecognitionHandler::ENTITY_TYPE_DATE,
            EntityRecognitionHandler::ENTITY_TYPE_SSN,
            EntityRecognitionHandler::ENTITY_TYPE_PHONE,
            EntityRecognitionHandler::ENTITY_TYPE_IP_ADDRESS,
        ];

        if (in_array($entityType, $delimited, true) === true) {
            return self::POLICY_DELIMITED_TOKEN;
        }

        // PERSON / ORGANIZATION / LOCATION / ADDRESS and every unenumerated
        // type. Bounded is the safe default because its failures are reported.
        return self::POLICY_BOUNDED;

    }//end forType()

    /**
     * Whether a match at [$start, $end) is allowed under the type's policy.
     *
     * @param array<int, string> $chars      The ORIGINAL text as a codepoint array.
     * @param integer            $start      Inclusive start offset of the match.
     * @param integer            $end        Exclusive end offset of the match.
     * @param string             $entityType The entity type being matched.
     *
     * @return boolean
     */
    public function allows(array $chars, int $start, int $end, string $entityType): bool
    {
        return $this->allowsUnderPolicy(
            chars: $chars,
            start: $start,
            end: $end,
            policy: $this->forType(entityType: $entityType)
        );

    }//end allows()

    /**
     * Whether a match at [$start, $end) is allowed under an EXPLICIT policy.
     *
     * Used when the caller knows the policy but has no entity type — notably
     * ad-hoc find/replace via `DocumentProcessingHandler::replaceWords()`, which
     * is not entity anonymisation and must keep literal substring semantics.
     *
     * @param array<int, string> $chars  The ORIGINAL text as a codepoint array.
     * @param integer            $start  Inclusive start offset of the match.
     * @param integer            $end    Exclusive end offset of the match.
     * @param string             $policy One of the POLICY_* constants.
     *
     * @return boolean
     */
    public function allowsUnderPolicy(array $chars, int $start, int $end, string $policy): bool
    {
        if ($policy === self::POLICY_LITERAL) {
            return true;
        }

        if ($this->isWordBounded(chars: $chars, start: $start, end: $end) === false) {
            return false;
        }

        if ($policy === self::POLICY_DELIMITED_TOKEN) {
            return $this->isWholeNumericToken(chars: $chars, start: $start, end: $end);
        }

        return true;

    }//end allowsUnderPolicy()

    /**
     * No word codepoint directly before or after the match.
     *
     * A word codepoint is a Unicode letter, combining mark, decimal digit or
     * underscore. The check is Unicode-aware on purpose: a non-`/u` `\b` is
     * byte-oriented and mis-fires on any accented Dutch name.
     *
     * @param array<int, string> $chars The original text as a codepoint array.
     * @param integer            $start Inclusive start offset.
     * @param integer            $end   Exclusive end offset.
     *
     * @return boolean
     */
    private function isWordBounded(array $chars, int $start, int $end): bool
    {
        if ($start > 0 && $this->isWordCodepoint(char: $chars[($start - 1)]) === true) {
            return false;
        }

        if ($end < count($chars) && $this->isWordCodepoint(char: $chars[$end]) === true) {
            return false;
        }

        return true;

    }//end isWordBounded()

    /**
     * Whether the match is the WHOLE numeric token it sits in, not a prefix,
     * suffix or middle of a longer one.
     *
     * A numeric token is a digit run optionally joined by single separators from
     * NUMERIC_SEPARATORS, where each separator is immediately followed by a
     * digit. Expansion is attempted outward from the match; if it grows, the
     * match was embedded and is rejected.
     *
     * @param array<int, string> $chars The original text as a codepoint array.
     * @param integer            $start Inclusive start offset.
     * @param integer            $end   Exclusive end offset.
     *
     * @return boolean
     */
    private function isWholeNumericToken(array $chars, int $start, int $end): bool
    {
        // Expand left: a separator only extends the token when a digit sits on
        // its far side, so `.` in `03.08` extends but `.` in `1980.` does not.
        $cursor = $start;
        while ($cursor > 0) {
            $previous = $chars[($cursor - 1)];
            if ($this->isDigit(char: $previous) === true) {
                $cursor--;
                continue;
            }

            $isSeparator    = in_array($previous, self::NUMERIC_SEPARATORS, true);
            $hasDigitBeyond = ($cursor >= 2 && $this->isDigit(char: $chars[($cursor - 2)]) === true);
            if ($isSeparator === true && $hasDigitBeyond === true) {
                $cursor -= 2;
                continue;
            }

            break;
        }//end while

        if ($cursor < $start) {
            return false;
        }

        // Expand right, mirror image.
        $total  = count($chars);
        $cursor = $end;
        while ($cursor < $total) {
            $next = $chars[$cursor];
            if ($this->isDigit(char: $next) === true) {
                $cursor++;
                continue;
            }

            $isSeparator    = in_array($next, self::NUMERIC_SEPARATORS, true);
            $hasDigitBeyond = (($cursor + 1) < $total && $this->isDigit(char: $chars[($cursor + 1)]) === true);
            if ($isSeparator === true && $hasDigitBeyond === true) {
                $cursor += 2;
                continue;
            }

            break;
        }//end while

        return ($cursor === $end);

    }//end isWholeNumericToken()

    /**
     * Unicode letter, combining mark, decimal digit or underscore.
     *
     * @param string $char A single codepoint.
     *
     * @return boolean
     */
    private function isWordCodepoint(string $char): bool
    {
        if ($char === '_') {
            return true;
        }

        return (preg_match('/^[\p{L}\p{M}\p{Nd}]$/u', $char) === 1);

    }//end isWordCodepoint()

    /**
     * ASCII-or-Unicode decimal digit.
     *
     * @param string $char A single codepoint.
     *
     * @return boolean
     */
    private function isDigit(string $char): bool
    {
        return (preg_match('/^\p{Nd}$/u', $char) === 1);

    }//end isDigit()
}//end class
