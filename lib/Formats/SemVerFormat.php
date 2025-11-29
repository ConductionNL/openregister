<?php

/**
 * Semantic Version Format Validator
 *
 * Validates semantic version strings according to SemVer specification.
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
 * Semantic Version (SemVer) format validator
 *
 * Validates that a string follows the Semantic Versioning specification (semver.org)
 * Format: MAJOR.MINOR.PATCH[-PRERELEASE][+BUILD]
 *
 * Examples of valid versions:
 * - 1.0.0
 * - 1.2.3
 * - 1.0.0-alpha
 * - 1.0.0-alpha.1
 * - 1.0.0-0.3.7
 * - 1.0.0-x.7.z.92
 * - 1.0.0+20130313144700
 * - 1.0.0-beta+exp.sha.5114f85
 * - 1.0.0+21AF26D3-117B344092BD
 *
 * @category Service
 * @package  OpenRegister
 * @author   Conduction AI <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.conduction.nl
 */
class SemVerFormat implements Format
{

    /**
     * Regular expression pattern for Semantic Versioning
     *
     * Based on the official SemVer regex from semver.org.
     *
     * @var string
     */
    private const SEMVER_PATTERN = <<<'REGEX'
/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)
(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)
(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?
(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/
REGEX;


    /**
     * Validates if a given value conforms to the Semantic Versioning format
     *
     * @param mixed $data The data to validate against the SemVer format
     *
     * @inheritDoc
     *
     * @return bool True if data is a valid semantic version, false otherwise
     */
    public function validate(mixed $data): bool
    {
        // Only validate strings.
        if (is_string($data) === false) {
            return false;
        }

        // Validate against SemVer pattern.
        return preg_match(self::SEMVER_PATTERN, $data) === 1;

    }//end validate()


}//end class
