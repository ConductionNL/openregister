<?php

/**
 * OAS validation report value object.
 *
 * Collects errors, warnings, and auto-corrections produced while validating
 * a generated OpenAPI specification. Each issue carries a JSON Pointer path
 * (per RFC 6901, e.g. `paths./objects/foo/bar.get.responses.200`) so the
 * caller can pinpoint the violation, plus a stable machine code that allows
 * downstream consumers (CI, dashboards) to filter or aggregate by issue
 * type without parsing free-form text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Collects validation issues for a single OAS generation pass.
 */
final class OasValidationReport
{

    /**
     * Severity codes.
     */
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_AUTO_CORRECTED = 'auto_corrected';

    /**
     * Issue codes — stable machine identifiers used by CI tooling.
     */
    public const CODE_DANGLING_REF = 'dangling_ref';

    public const CODE_INVALID_ALLOF = 'invalid_allof';

    public const CODE_DUPLICATE_OPERATION_ID = 'duplicate_operation_id';

    public const CODE_ORPHAN_TAG = 'orphan_tag';

    public const CODE_UNUSED_TAG = 'unused_tag';

    public const CODE_INVALID_HTTP_METHOD = 'invalid_http_method';

    public const CODE_INVALID_STATUS_CODE = 'invalid_status_code';

    public const CODE_INVALID_PROPERTY_TYPE = 'invalid_property_type';

    public const CODE_MISSING_ARRAY_ITEMS = 'missing_array_items';

    public const CODE_RELATIVE_SERVER_URL = 'relative_server_url';

    /**
     * Issues collected during validation.
     *
     * @var list<array{path: string, message: string, code: string, severity: string}>
     */
    private array $issues = [];

    /**
     * Record a hard error.
     *
     * @param string $path    JSON Pointer path identifying the offending location.
     * @param string $message Human-readable description of the failure.
     * @param string $code    Stable machine code (one of the CODE_* constants).
     *
     * @return void
     */
    public function addError(string $path, string $message, string $code): void
    {
        $this->issues[] = [
            'path'     => $path,
            'message'  => $message,
            'code'     => $code,
            'severity' => self::SEVERITY_ERROR,
        ];

    }//end addError()

    /**
     * Record a non-blocking warning.
     *
     * @param string $path    JSON Pointer path identifying the offending location.
     * @param string $message Human-readable description.
     * @param string $code    Stable machine code (one of the CODE_* constants).
     *
     * @return void
     */
    public function addWarning(string $path, string $message, string $code): void
    {
        $this->issues[] = [
            'path'     => $path,
            'message'  => $message,
            'code'     => $code,
            'severity' => self::SEVERITY_WARNING,
        ];

    }//end addWarning()

    /**
     * Record an auto-correction the validator applied to keep the document valid.
     *
     * @param string $path    JSON Pointer path identifying the corrected location.
     * @param string $message Human-readable description of the correction.
     * @param string $code    Stable machine code (one of the CODE_* constants).
     *
     * @return void
     */
    public function addAutoCorrection(string $path, string $message, string $code): void
    {
        $this->issues[] = [
            'path'     => $path,
            'message'  => $message,
            'code'     => $code,
            'severity' => self::SEVERITY_AUTO_CORRECTED,
        ];

    }//end addAutoCorrection()

    /**
     * Returns every issue regardless of severity.
     *
     * @return list<array{path: string, message: string, code: string, severity: string}>
     */
    public function getIssues(): array
    {
        return $this->issues;

    }//end getIssues()

    /**
     * Returns only the error-severity issues.
     *
     * @return list<array{path: string, message: string, code: string, severity: string}>
     */
    public function getErrors(): array
    {
        return $this->filterBySeverity(severity: self::SEVERITY_ERROR);

    }//end getErrors()

    /**
     * Returns only the warning-severity issues.
     *
     * @return list<array{path: string, message: string, code: string, severity: string}>
     */
    public function getWarnings(): array
    {
        return $this->filterBySeverity(severity: self::SEVERITY_WARNING);

    }//end getWarnings()

    /**
     * Returns only the auto-corrected issues.
     *
     * @return list<array{path: string, message: string, code: string, severity: string}>
     */
    public function getAutoCorrections(): array
    {
        return $this->filterBySeverity(severity: self::SEVERITY_AUTO_CORRECTED);

    }//end getAutoCorrections()

    /**
     * True when at least one error-severity issue has been recorded.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->getErrors() !== [];

    }//end hasErrors()

    /**
     * True when no error-severity issues have been recorded.
     *
     * @return bool
     */
    public function passed(): bool
    {
        return $this->hasErrors() === false;

    }//end passed()

    /**
     * True when no issues at all have been recorded.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->issues === [];

    }//end isEmpty()

    /**
     * Compact summary intended for the `x-validation-summary` extension
     * field on the generated OAS document.
     *
     * @return array<string, mixed>
     *   passed         (bool)         True when no error-severity issues recorded.
     *   errors         (int)          Count of error-severity issues.
     *   warnings       (int)          Count of warning-severity issues.
     *   autoCorrected  (int)          Count of auto-corrected issues.
     *   issues         (list<array>)  Full issue list with path/message/code/severity.
     */
    public function toSummary(): array
    {
        return [
            'passed'        => $this->passed(),
            'errors'        => count($this->getErrors()),
            'warnings'      => count($this->getWarnings()),
            'autoCorrected' => count($this->getAutoCorrections()),
            'issues'        => $this->issues,
        ];

    }//end toSummary()

    /**
     * Filter the recorded issues by severity.
     *
     * @param string $severity One of the SEVERITY_* constants.
     *
     * @return list<array{path: string, message: string, code: string, severity: string}>
     */
    private function filterBySeverity(string $severity): array
    {
        return array_values(
            array: array_filter(
                $this->issues,
                static fn (array $issue): bool => $issue['severity'] === $severity,
            )
        );

    }//end filterBySeverity()
}//end class
