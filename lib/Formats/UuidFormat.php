<?php

/**
 * OpenRegister UuidFormat
 *
 * This file contains the single shared format/validator for UUID values,
 * consolidating the UUID regex that was previously copy-pasted across 35+ call
 * sites (ADR-008: one validator per rule, in lib/Formats/).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * The single shared UUID validator.
 *
 * Supported shapes are explicit, named options so a fix propagates everywhere
 * instead of drifting between per-site copies:
 *  - canonical: 8-4-4-4-12 hex with hyphens (the JSON-Schema `format: uuid`).
 *  - prefixed:  an app prefix + `-` + a canonical UUID (e.g. `foo-uuid-...`).
 *  - hex32:     32 unbroken hex characters (no hyphens).
 */
class UuidFormat implements Format
{
    /**
     * Canonical 8-4-4-4-12 UUID.
     */
    private const CANONICAL = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * A lowercase-letter prefix, a hyphen, then a canonical UUID.
     */
    private const PREFIXED = '/^[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * 32 unbroken hex characters.
     */
    private const HEX32 = '/^[0-9a-f]{32}$/i';

    /**
     * JSON-Schema Format hook — validates the canonical UUID shape.
     *
     * @param mixed $data The value to validate.
     *
     * @inheritDoc
     *
     * @return bool True if the value is a canonical UUID.
     */
    public function validate(mixed $data): bool
    {
        return is_string($data) === true && self::isCanonical($data) === true;
    }//end validate()

    /**
     * True when the value is a canonical 8-4-4-4-12 UUID.
     *
     * @param string $value The value to test.
     *
     * @return bool
     */
    public static function isCanonical(string $value): bool
    {
        return preg_match(self::CANONICAL, $value) === 1;
    }//end isCanonical()

    /**
     * True when the value is a canonical UUID with an app prefix.
     *
     * @param string $value The value to test.
     *
     * @return bool
     */
    public static function isPrefixed(string $value): bool
    {
        return preg_match(self::PREFIXED, $value) === 1;
    }//end isPrefixed()

    /**
     * True when the value is 32 unbroken hex characters.
     *
     * @param string $value The value to test.
     *
     * @return bool
     */
    public static function isHex32(string $value): bool
    {
        return preg_match(self::HEX32, $value) === 1;
    }//end isHex32()

    /**
     * True when the value matches any supported UUID shape.
     *
     * Call sites that previously accepted a prefixed or 32-hex form use this
     * instead of carrying their own divergent regex.
     *
     * @param string $value The value to test.
     *
     * @return bool
     */
    public static function isAny(string $value): bool
    {
        return self::isCanonical($value) === true
            || self::isPrefixed($value) === true
            || self::isHex32($value) === true;
    }//end isAny()
}//end class
