<?php

/**
 * OpenRegister PdfAnonymisationException
 *
 * Exception thrown when the PDF anonymisation pipeline cannot produce a valid
 * anonymised output. Carries a structured `reason` (one of a fixed enum) so
 * the controller layer can map the failure to the correct HTTP status without
 * exposing PII in error messages (per ADR-005).
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Exception thrown by the PDF anonymisation pipeline.
 *
 * The `reason` field is a stable, structured code that the controller layer
 * maps to an HTTP status:
 *
 *   - `REASON_ENCRYPTED_PDF`       → HTTP 422 (caller-correctable)
 *   - `REASON_TEXT_LAYER_MISSING`  → defer to `ocr-document-scanning`
 *   - `REASON_VALIDATION_FAILED`   → HTTP 500 (pipeline integrity failure)
 *   - `REASON_INTERNAL_ERROR`      → HTTP 500 (unexpected pipeline failure)
 *
 * `$diagnostic` is a free-form array used for error logging and debugging.
 * Per ADR-005 it MUST NOT contain operator-supplied entity text — only
 * structural information (entity count, font names encountered, filter
 * chains decoded, etc.).
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
 */
class PdfAnonymisationException extends \Exception
{

    /**
     * Encrypted PDF input — anonymising a password-protected PDF requires
     * the password and is out of scope for v1. Controller maps to HTTP 422.
     *
     * @var string
     */
    public const REASON_ENCRYPTED_PDF = 'encrypted_pdf';

    /**
     * Input PDF has no extractable text layer (image-only scan). Caller
     * SHOULD defer to the `ocr-document-scanning` capability rather than
     * surfacing as a hard error.
     *
     * @var string
     */
    public const REASON_TEXT_LAYER_MISSING = 'text_layer_missing';

    /**
     * Post-replacement validation gate detected residual entity text in
     * the output PDF. The output is discarded; the gate fails closed.
     * Controller maps to HTTP 500 with a structured diagnostic body.
     *
     * @var string
     */
    public const REASON_VALIDATION_FAILED = 'validation_failed';

    /**
     * Catch-all for unexpected pipeline failures (SAPP errors, malformed
     * input, etc.). Controller maps to HTTP 500.
     *
     * @var string
     */
    public const REASON_INTERNAL_ERROR = 'internal_error';

    /**
     * Path A's strict-mode validation gate failed AND the dormant Path B
     * (NC Office ODT round-trip — `pdf-anonymisation-odt-fallback`) was
     * attempted but also failed to produce a clean output. Controller maps
     * to HTTP 500 with the structured diagnostic body, identical to
     * REASON_VALIDATION_FAILED's mapping.
     *
     * Only raised when `pdf-anonymisation.path-b-enabled` is true AND a
     * Path B attempt was made. Until the feature flag flips on this reason
     * code is reserved (no caller produces it).
     *
     * @var string
     */
    public const REASON_VALIDATION_FAILED_AFTER_FALLBACK = 'validation_failed_after_fallback';

    /**
     * The structured reason code (one of the REASON_* constants).
     *
     * @var string
     */
    private readonly string $reason;

    /**
     * Free-form structured diagnostic surface, safe for logging.
     *
     * MUST NOT contain operator-supplied entity text (PII).
     *
     * @var array<string, mixed>
     */
    private readonly array $diagnostic;

    /**
     * Constructor.
     *
     * @param string         $reason     One of the REASON_* constants
     * @param string         $message    Human-readable error message (PII-free)
     * @param array          $diagnostic Structural diagnostic info; MUST NOT contain PII
     * @param Throwable|null $previous   Previous exception
     *
     * @phpstan-param array<string, mixed> $diagnostic
     * @psalm-param   array<string, mixed> $diagnostic
     *
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
     */
    public function __construct(
        string $reason,
        string $message='',
        array $diagnostic=[],
        ?Throwable $previous=null
    ) {
        $this->reason     = $reason;
        $this->diagnostic = $diagnostic;

        $finalMessage = $message;
        if ($message === '') {
            $finalMessage = sprintf('PDF anonymisation failed: %s', $reason);
        }

        parent::__construct(message: $finalMessage, code: 0, previous: $previous);
    }//end __construct()

    /**
     * Get the structured reason code.
     *
     * @return string One of the REASON_* constants
     *
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
     */
    public function getReason(): string
    {
        return $this->reason;
    }//end getReason()

    /**
     * Get the structural diagnostic surface (PII-free).
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
     */
    public function getDiagnostic(): array
    {
        return $this->diagnostic;
    }//end getDiagnostic()
}//end class
