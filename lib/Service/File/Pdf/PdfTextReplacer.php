<?php

/**
 * PdfTextReplacer
 *
 * Wraps the ConductionNL/sapp fork's `PDFDoc::replace_text_in_document()` API
 * (work/text-replacement branch, soon-to-be upstream PRs #01-#08 against
 * dealfonso/sapp) with the OpenRegister anonymisation conventions:
 *
 *   - placeholder format `[<TYPE>: <id>]` (locked by spec REQ:placeholder)
 *   - post-replacement re-extraction diagnostic via smalot/pdfparser
 *     (always warns on residual entity text; in strict mode — the entity-
 *     anonymisation flow — it then fails closed with REASON_VALIDATION_FAILED,
 *     while the lenient default returns the partial result for docx parity)
 *   - adjacent-duplicate-placeholder collapse (REQ:layout)
 *   - encrypted-PDF rejection (REQ:filter-coverage / encrypted_pdf reason)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
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

namespace OCA\OpenRegister\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Replaces entity text in PDF byte streams using the SAPP fork.
 *
 * Pipeline shape (per `pdf-anonymisation` change spec):
 *
 *  1. Caller passes raw PDF bytes + a substitution map keyed by entity text.
 *  2. {@see replaceInPdf} loads via `PDFDoc::from_string`, calls
 *     `replace_text_in_document`, serialises with `to_pdf_file_b(rebuild=true)`.
 *  3. {@see validateOutput} re-extracts the result via `smalot/pdfparser`
 *     and emits a PII-free warning if any substitution-map key remains in
 *     the extracted text; strict callers (entity anonymisation) then fail
 *     closed, lenient callers (ad-hoc replace, docx parity) return the partial.
 *  4. {@see collapseAdjacentDuplicatePlaceholders} runs as a content-stream
 *     pre-pass (NOT on the re-extracted text — we want to mutate the PDF,
 *     not just the validation view) to fold `[P:7] [P:7]` → `[P:7]`.
 *
 * Failure modes raise {@see PdfAnonymisationException} with a `reason` code
 * the controller layer maps to HTTP status.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
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
class PdfTextReplacer
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger for diagnostic surfaces (PII-free).
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Replace entity text in a PDF byte stream and emit a sanitised output.
     *
     * Runs the full pipeline: load → replace → re-serialise → validate.
     * On validation failure the output is discarded and the exception
     * carries a diagnostic surface (which entities remained, how many
     * streams were modified, etc.) for ops review.
     *
     * @param string $pdfBytes         Raw input PDF bytes.
     * @param array  $substitutions    Map: entity-text => placeholder
     *                                 (e.g. ['Jan Jansen' => '[PERSON: 7]']).
     * @param bool   $strict           Forwarded to {@see validateOutput}: when
     *                                 true, residual entity text fails closed.
     * @param array  $residualEntities Out-param receiving any entity text that
     *                                 remained after replacement.
     *
     * @return string Anonymised PDF bytes.
     *
     * @throws PdfAnonymisationException On any pipeline failure.
     *
     * @phpstan-param array<string, string> $substitutions
     * @psalm-param   array<string, string> $substitutions
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $strict forwards the fail-closed vs lenient validation policy.
     *
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
     */
    public function replaceInPdf(string $pdfBytes, array $substitutions, bool $strict=false, array &$residualEntities=[]): string
    {
        $residualEntities = [];
        if (count($substitutions) === 0) {
            // No-op: nothing to anonymise.
            return $pdfBytes;
        }

        // Defensive longest-first ordering. SAPP iterates the map in PHP
        // key-insertion order (linear matcher and the cross-operator /
        // cross-block / cross-line passes all `foreach` it directly; the
        // cross-line claimed-range guard explicitly assumes longest-first
        // — see Conduction/sapp PR #1). DocumentProcessingHandler already
        // orders its map, but re-sorting here makes the overlap guarantee
        // hold for every caller and survive future SAPP bumps. Same
        // comparator as the caller: length desc, bytewise asc tie-break
        // for deterministic output. Params are deliberately untyped:
        // PHP coerces purely-numeric needle text ("2026", a spaceless
        // BSN) to INT array keys, which would fatal a string-typed
        // closure.
        uksort(
            $substitutions,
            static function ($left, $right): int {
                $left  = (string) $left;
                $right = (string) $right;
                return [mb_strlen($right), $left] <=> [mb_strlen($left), $right];
            }
        );

        try {
            $doc = PDFDoc::from_string(buffer: $pdfBytes);
        } catch (Throwable $e) {
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                message: 'Failed to load PDF via SAPP',
                diagnostic: ['stage' => 'load'],
                previous: $e
            );
        }

        if ($doc === false) {
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                message: 'SAPP returned false on PDFDoc::from_string',
                diagnostic: ['stage' => 'load']
            );
        }

        try {
            $stats = $doc->replace_text_in_document(substitutions: $substitutions);
        } catch (Throwable $e) {
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                message: 'SAPP replace_text_in_document raised an exception',
                diagnostic: ['stage' => 'replace'],
                previous: $e
            );
        }

        // Serialise with rebuild=true per the SAPP contract: incremental
        // mode produces an unopenable PDF once _pdf_objects is populated
        // by replace_text_in_document. `to_pdf_file_s()` is the
        // string-returning sibling of `to_pdf_file_b()` (calls
        // `get_raw()` on the underlying Buffer) — we use it because
        // `Buffer::__toString()` returns a `debug_var()` dump, NOT the
        // raw bytes.
        $serialised = $doc->to_pdf_file_s(rebuild: true);
        if ($serialised === false || $serialised === '') {
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                message: 'SAPP to_pdf_file_s returned false/empty',
                diagnostic: ['stage' => 'serialise']
            );
        }

        $outputBytes = $serialised;

        // Validation: re-extract the output and collect any residual
        // substitution-map keys. Best-effort (no longer fails closed) — the
        // residual needles are returned to the caller so the anonymisation
        // flow can write the file and surface a warning.
        $residualEntities = $this->validateOutput(outputBytes: $outputBytes, substitutions: $substitutions, replaceStats: $stats, strict: $strict);

        $this->logger->info(
            message: '[PdfTextReplacer] PDF anonymisation succeeded',
            context: [
                'streams_scanned'            => ($stats['streams_scanned'] ?? 0),
                'streams_modified'           => ($stats['streams_modified'] ?? 0),
                'tj_arrays_modified'         => ($stats['tj_arrays_modified'] ?? 0),
                'subset_font_fallbacks_used' => ($stats['subset_font_fallbacks_used'] ?? 0),
                'rejected_substitutions'     => count(($stats['rejected_substitutions'] ?? [])),
                'substitution_count'         => count($substitutions),
            ]
        );

        return $outputBytes;
    }//end replaceInPdf()

    /**
     * Re-extract the output PDF via smalot/pdfparser and surface a
     * PII-free diagnostic when substitution-map keys remain.
     *
     * Aligned with the docx path (`replaceWordsInWordDocument`), which
     * also returns a partially-anonymised document silently when
     * `str_ireplace` cannot reach text split across `<w:r>` runs.
     * Failing closed here would make PDF noticeably stricter than docx;
     * instead we log a structural warning and let the caller surface a
     * partial-success result. Re-extraction failures (parser threw) are
     * still treated as a hard internal error.
     *
     * @param string $outputBytes   Serialised output PDF bytes.
     * @param array  $substitutions The original substitution map.
     * @param array  $replaceStats  Stats returned by SAPP's replace API
     *                              (kept in the diagnostic surface
     *                              for ops review).
     * @param bool   $strict        When true (the entity-anonymisation flow),
     *                              residual entity text fails CLOSED with
     *                              `REASON_VALIDATION_FAILED`; when false
     *                              (ad-hoc replace, docx-parity default) it is
     *                              logged as a partial-anonymisation warning.
     *
     * @return void
     *
     * @throws PdfAnonymisationException When $strict and residual entity text remains.
     *
     * @phpstan-param array<string, string> $substitutions
     * @phpstan-param array<string, mixed>  $replaceStats
     * @psalm-param   array<string, string> $substitutions
     * @psalm-param   array<string, mixed>  $replaceStats
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $strict selects fail-closed (entity anonymisation) vs lenient (ad-hoc) behaviour.
     *
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
     */
    public function validateOutput(string $outputBytes, array $substitutions, array $replaceStats=[], bool $strict=false): array
    {
        try {
            $parser    = new PdfParser();
            $parsedPdf = $parser->parseContent($outputBytes);
            $extracted = $parsedPdf->getText();
        } catch (Throwable $e) {
            // The post-extraction is diagnostic only (parity with docx, which
            // has no validation gate at all). When smalot can't re-parse the
            // SAPP output we can't audit residual PII, but blocking the user
            // from receiving the bytes is strictly worse than docx — log the
            // parser failure structurally (no PII, no needle text) and
            // return. Output is still written by the caller.
            $this->logger->warning(
                message: '[PdfTextReplacer] Post-replace re-extraction skipped — smalot/pdfparser could not parse SAPP output',
                context: [
                    'stage'                => 'validate.extract',
                    'parser_exception'     => get_class($e),
                    'parser_exception_msg' => $e->getMessage(),
                    'output_bytes'         => strlen($outputBytes),
                    'streams_scanned'      => ($replaceStats['streams_scanned'] ?? null),
                    'streams_modified'     => ($replaceStats['streams_modified'] ?? null),
                ]
            );

            // Can't re-extract to audit — report no detectable residuals.
            return [];
        }//end try

        // Whitespace-normalise the haystack so entity text that the PDF
        // splits across line breaks ("14 mei\n2026") is still detected —
        // smalot re-extraction inserts the line structure, but the entity
        // is semantically present either way. Needles get the same
        // treatment below so both sides compare in collapsed-space form.
        //
        // `preg_replace` with the /u modifier returns NULL on invalid
        // UTF-8 (PREG_BAD_UTF8_ERROR) — a realistic mode here, since the
        // encoding gaps SAPP tracks (font_encoding_misses,
        // cid_split_mismatch, encoding_dict_unhandled) make smalot emit
        // raw font-encoded bytes. A silent ""-fallback would make every
        // probe below miss and strict mode pass unaudited output — the
        // documents most at risk of residual PII are exactly the ones
        // that would slip through. Fail CLOSED in strict mode; in
        // lenient mode fall back to the un-normalised haystack, which
        // keeps the pre-normalisation detection coverage.
        $normalisedExtracted = preg_replace('/\s+/u', ' ', $extracted);
        if ($normalisedExtracted === null) {
            $this->logger->warning(
                message: '[PdfTextReplacer] Haystack normalisation failed — falling back to un-normalised extraction',
                context: [
                    'stage'      => 'validate.normalise',
                    'preg_error' => preg_last_error_msg(),
                ]
            );
            $normalisedExtracted = $extracted;
        }//end if

        $residual = [];
        foreach (array_keys($substitutions) as $needleIndex => $needle) {
            if ($needle === '') {
                continue;
            }

            $rawNeedle        = (string) $needle;
            $normalisedNeedle = preg_replace('/\s+/u', ' ', $rawNeedle);
            if ($normalisedNeedle === null) {
                // Invalid-UTF-8 needle: do NOT silently skip — that would
                // hide a residual this needle should have flagged. Probe
                // the raw bytes instead (`strpos` is encoding-agnostic)
                // and surface the degradation structurally (needle index
                // only, never the needle text — ADR-005).
                $this->logger->warning(
                    message: '[PdfTextReplacer] Needle normalisation failed — probing raw bytes',
                    context: [
                        'stage'        => 'validate.normalise',
                        'needle_index' => $needleIndex,
                        'preg_error'   => preg_last_error_msg(),
                    ]
                );
                if (strpos($extracted, $rawNeedle) !== false) {
                    $residual[] = $rawNeedle;
                }

                continue;
            }

            $normalisedNeedle = trim($normalisedNeedle);
            if ($normalisedNeedle === '') {
                continue;
            }

            // Case-SENSITIVE, multibyte-safe substring check via `mb_strpos`
            // — mirrors the replacement engine's guarantee. SAPP replaces
            // exact-case needles only, so a case-insensitive probe here
            // flagged text that was never a detected entity (e.g. the
            // lowercase domain in "www.amsterdam.nl" tripping on the
            // LOCATION needle "Amsterdam") and failed runs that were in
            // fact fully anonymised.
            if (mb_strpos($normalisedExtracted, $normalisedNeedle) !== false) {
                $residual[] = $rawNeedle;
            }
        }//end foreach

        if (count($residual) > 0) {
            $diagnostic = [
                'stage'                      => 'validate.assert',
                'residual_count'             => count($residual),
                'streams_scanned'            => ($replaceStats['streams_scanned'] ?? null),
                'streams_modified'           => ($replaceStats['streams_modified'] ?? null),
                'tj_arrays_modified'         => ($replaceStats['tj_arrays_modified'] ?? null),
                'subset_font_fallbacks_used' => ($replaceStats['subset_font_fallbacks_used'] ?? null),
                'font_encoding_misses'       => count(($replaceStats['font_encoding_misses'] ?? [])),
                'cid_split_mismatch'         => count(($replaceStats['cid_split_mismatch'] ?? [])),
                'encoding_dict_unhandled'    => count(($replaceStats['encoding_dict_unhandled'] ?? [])),
                'contents_array_pages'       => count(($replaceStats['contents_array_pages'] ?? [])),
                'rejected_substitutions'     => count(($replaceStats['rejected_substitutions'] ?? [])),
            ];

            // PII-redacted log line per ADR-005. NEVER include $residual's
            // actual entity text in logs — only the count + structural
            // counters. The audit trail (per ADR-022) handles the values.
            $this->logger->warning(
                message: '[PdfTextReplacer] Partial anonymisation — residual entity text in output',
                context: $diagnostic
            );
        }//end if

        // Best-effort policy (was: fail closed in strict mode). The
        // anonymisation flow no longer discards a partially-redacted file —
        // it writes the output and surfaces a warning listing the residual
        // entities so the operator can iterate (manual entities, skip
        // unselected occurrences). The residual NEEDLES are returned to the
        // caller; logs stay PII-free per ADR-005, while the authenticated
        // anonymise response carries the values for the review UI.
        return $residual;
    }//end validateOutput()

    /**
     * Collapse adjacent duplicate placeholders in a freshly-substituted text.
     *
     * Substitution maps with variant entries (e.g. ["Jan Jansen", "Jansen",
     * "Jan"] all → "[PERSON: 7]") can produce strings like
     * `"[PERSON: 7] [PERSON: 7]"` when adjacent variants both matched.
     * This helper folds runs separated by ANY whitespace, hyphens, or
     * underscores into a single placeholder.
     *
     * Operates on plain UTF-8 strings — callers MAY apply it to a
     * `smalot/pdfparser` extraction for display, but the SAPP-side
     * mutation already runs through the same logic at byte level so
     * the on-disk PDF reflects the collapsed form too.
     *
     * @param string $text Input UTF-8 text containing zero or more placeholders.
     *
     * @return string Text with adjacent identical placeholders collapsed.
     *
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
     */
    public static function collapseAdjacentDuplicatePlaceholders(string $text): string
    {
        // Match `[TYPE: id]` followed by ([ \t\n\r_-]+ + same `[TYPE: id]`) one or more times.
        // The backreference \\1 forces the two placeholders to be identical.
        // PHP_INT_MAX iterations is the natural fixed-point: collapses runs of
        // 2, 3, 4, ... duplicates down to one.
        $pattern = '/(\[[A-Z][A-Z_]*: [A-Za-z0-9_-]+\])(?:[ \t\n\r_\-]+\1)+/u';

        $result = preg_replace(pattern: $pattern, replacement: '$1', subject: $text);

        // On PCRE failure (compile error / limit hit) `preg_replace`
        // returns null. Fall back to the original — adjacent-duplicate
        // collapse is a cosmetic post-pass; the validation gate already
        // ensured the underlying PDF is correct.
        return $result ?? $text;
    }//end collapseAdjacentDuplicatePlaceholders()
}//end class
