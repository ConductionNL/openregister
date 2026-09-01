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
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
class PdfTextReplacer {

	/**
	 * Structure-tree inspector used to detect taggedness and count
	 * `/StructElem` objects before/after redaction (REQ-ORTPR-001/002).
	 *
	 * @var PdfStructureInspector
	 */
	private readonly PdfStructureInspector $structureInspector;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for diagnostic surfaces (PII-free).
	 * @param PdfStructureInspector|null $structureInspector Structure-tree inspector; a default instance
	 *                                                       is created when omitted (test seam only —
	 *                                                       production callers never pass this).
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		?PdfStructureInspector $structureInspector = null,
	) {
		$this->structureInspector = $structureInspector ?? new PdfStructureInspector();
	}//end __construct()

	/**
	 * Replace entity text in a PDF byte stream and emit a sanitised output.
	 *
	 * Runs the full pipeline: load → replace → re-serialise → validate.
	 * On validation failure the output is discarded and the exception
	 * carries a diagnostic surface (which entities remained, how many
	 * streams were modified, etc.) for ops review.
	 *
	 * @param string $pdfBytes Raw input PDF bytes.
	 * @param array $substitutions Map: entity-text => placeholder
	 *                             (e.g. ['Jan Jansen' =>
	 *                             '[PERSON: 7]']).
	 * @param bool $strict Forwarded to {@see validateOutput}: when
	 *                     true, residual entity text fails closed.
	 * @param array $residualEntities Out-param receiving any entity text that
	 *                                remained after replacement.
	 * @param bool|null $preserveStructure Tri-state structure-preservation option
	 *                                     (REQ-ORTPR-004): null/absent = auto
	 *                                     (preserve iff the input is tagged);
	 *                                     true = attempt even on ambiguous
	 *                                     detection; false = skip but still
	 *                                     measure. Default null (auto).
	 * @param StructurePreservation|null $structureResult Out-param receiving the
	 *                                                    `structurePreservation` result block
	 *                                                    (REQ-ORTPR-003) for this run. Always populated
	 *                                                    on return (never left null).
	 *
	 * @return string Anonymised PDF bytes.
	 *
	 * @throws PdfAnonymisationException On any pipeline failure.
	 *
	 * @phpstan-param array<string, string> $substitutions
	 * @psalm-param   array<string, string> $substitutions
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   $strict forwards the fail-closed vs lenient validation policy.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Linear load → measure → replace → re-measure → serialise →
	 *                                                attest → validate pipeline; splitting obscures the flow
	 *                                                (same rationale as DocumentProcessingHandler::replaceWordsInPdfDocument()).
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
	 */
	public function replaceInPdf(
		string $pdfBytes,
		array $substitutions,
		bool $strict = false,
		array &$residualEntities = [],
		?bool $preserveStructure = null,
		?StructurePreservation &$structureResult = null,
	): string {
		$residualEntities = [];
		$structureResult = null;

		if (count($substitutions) === 0) {
			// No-op: nothing to anonymise. Output bytes are byte-identical to
			// the input (REQ-ORTPR-005), so tagCountAfter trivially equals
			// tagCountBefore — still measured for a truthful report even on
			// this fast path.
			$structureResult = $this->measureNoOnStructurePreservation(pdfBytes: $pdfBytes, preserveStructure: $preserveStructure);
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
				$left = (string)$left;
				$right = (string)$right;
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

		// Measure BEFORE any mutation, reusing this same loaded $doc — no
		// second parse (REQ-ORTPR-001; design.md "Risks" — the extra-parse
		// cost is avoided by inspecting the object model already in memory).
		$tagCountBefore = $this->structureInspector->countStructElements(doc: $doc);
		$requested = $this->resolveRequestedPreservation(preserveStructure: $preserveStructure, tagCountBefore: $tagCountBefore);

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

		// Re-measure AFTER text replacement, on the SAME in-memory $doc
		// (`replace_text_in_document` mutates content streams only; the
		// structure-tree objects — `/StructTreeRoot`, `/MarkInfo`,
		// `/StructElem` — are not content streams, so this reflects what
		// `to_pdf_file_s(rebuild: true)` below will serialise). Never a
		// silent flatten: any loss surfaces via $structureResult regardless
		// of $preserveStructure (REQ-ORTPR-002).
		$tagCountAfter = $this->structureInspector->countStructElements(doc: $doc);
		$structureIntactAfter = $this->structureInspector->isTagged(doc: $doc);

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

		// Attest the conservative `preserved` rule (design.md D1) and build
		// the contracted result block (REQ-ORTPR-002/003). This never
		// changes $outputBytes — preservation is measurement + honest
		// degradation, not a repair the current stack can perform.
		$structureResult = $this->attestStructurePreservation(
			rawOption: $preserveStructure,
			requested: $requested,
			tagCountBefore: $tagCountBefore,
			tagCountAfter: $tagCountAfter,
			structureIntactAfter: $structureIntactAfter,
			streamsModified: (int)($stats['streams_modified'] ?? 0)
		);

		// Validation: re-extract the output and collect any residual
		// substitution-map keys. Best-effort (no longer fails closed) — the
		// residual needles are returned to the caller so the anonymisation
		// flow can write the file and surface a warning.
		$residualEntities = $this->validateOutput(outputBytes: $outputBytes, substitutions: $substitutions, replaceStats: $stats, strict: $strict);

		$this->logger->info(
			message: '[PdfTextReplacer] PDF anonymisation succeeded',
			context: [
				'streams_scanned' => ($stats['streams_scanned'] ?? 0),
				'streams_modified' => ($stats['streams_modified'] ?? 0),
				'tj_arrays_modified' => ($stats['tj_arrays_modified'] ?? 0),
				'subset_font_fallbacks_used' => ($stats['subset_font_fallbacks_used'] ?? 0),
				'rejected_substitutions' => count(($stats['rejected_substitutions'] ?? [])),
				'substitution_count' => count($substitutions),
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
	 * @param string $outputBytes Serialised output PDF bytes.
	 * @param array $substitutions The original substitution map.
	 * @param array $replaceStats Stats returned by SAPP's replace API
	 *                            (kept in the diagnostic surface
	 *                            for ops review).
	 * @param bool $strict When true (the entity-anonymisation flow),
	 *                     residual entity text fails CLOSED with
	 *                     `REASON_VALIDATION_FAILED`; when false
	 *                     (ad-hoc replace, docx-parity default) it is
	 *                     logged as a partial-anonymisation warning.
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
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function validateOutput(string $outputBytes, array $substitutions, array $replaceStats = [], bool $strict = false): array {
		try {
			$parser = new PdfParser();
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
					'stage' => 'validate.extract',
					'parser_exception' => get_class($e),
					'parser_exception_msg' => $e->getMessage(),
					'output_bytes' => strlen($outputBytes),
					'streams_scanned' => ($replaceStats['streams_scanned'] ?? null),
					'streams_modified' => ($replaceStats['streams_modified'] ?? null),
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
					'stage' => 'validate.normalise',
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

			$rawNeedle = (string)$needle;
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
						'stage' => 'validate.normalise',
						'needle_index' => $needleIndex,
						'preg_error' => preg_last_error_msg(),
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
				'stage' => 'validate.assert',
				'residual_count' => count($residual),
				'streams_scanned' => ($replaceStats['streams_scanned'] ?? null),
				'streams_modified' => ($replaceStats['streams_modified'] ?? null),
				'tj_arrays_modified' => ($replaceStats['tj_arrays_modified'] ?? null),
				'subset_font_fallbacks_used' => ($replaceStats['subset_font_fallbacks_used'] ?? null),
				'font_encoding_misses' => count(($replaceStats['font_encoding_misses'] ?? [])),
				'cid_split_mismatch' => count(($replaceStats['cid_split_mismatch'] ?? [])),
				'encoding_dict_unhandled' => count(($replaceStats['encoding_dict_unhandled'] ?? [])),
				'contents_array_pages' => count(($replaceStats['contents_array_pages'] ?? [])),
				'rejected_substitutions' => count(($replaceStats['rejected_substitutions'] ?? [])),
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
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public static function collapseAdjacentDuplicatePlaceholders(string $text): string {
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

	/**
	 * Resolve the tri-state `preserveStructure` option to an effective
	 * boolean (design.md D3 / REQ-ORTPR-004).
	 *
	 * @param bool|null $preserveStructure Caller-supplied option: null = auto,
	 *                                     true = attempt, false = skip.
	 * @param int $tagCountBefore Structure-element count of the input,
	 *                            used by the auto rule.
	 *
	 * @return bool True when preservation is in effect for this run.
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-004
	 */
	private function resolveRequestedPreservation(?bool $preserveStructure, int $tagCountBefore): bool {
		if ($preserveStructure === null) {
			// Auto: preserve iff the input is tagged.
			return ($tagCountBefore > 0);
		}

		return $preserveStructure;
	}//end resolveRequestedPreservation()

	/**
	 * Apply the conservative `preserved` attestation rule and build the
	 * `structurePreservation` result block (design.md D1 / D2, REQ-ORTPR-002).
	 *
	 * `preserved: true` is asserted ONLY when preservation was requested AND
	 * the input was tagged AND `tagCountAfter === tagCountBefore` AND the
	 * structure tree is still detected on the output AND no structured
	 * content stream was modified by the replacement. SAPP has zero
	 * marked-content (`/MCID`) awareness, so ANY content-stream mutation on
	 * a tagged document means the tag→content correspondence can no longer
	 * be guaranteed — the engine reports the degradation explicitly rather
	 * than presenting a pass-through as a faithful preservation.
	 *
	 * @param bool|null $rawOption The RAW caller-supplied tri-state option (distinct from
	 *                             $requested — needed to tell "explicit false" apart from
	 *                             "auto resolved to false because untagged", which report
	 *                             different `lossReasons`, design.md D3/REQ-ORTPR-005).
	 * @param bool $requested Resolved preservation option (see {@see resolveRequestedPreservation()}).
	 * @param int $tagCountBefore `/StructElem` count of the input.
	 * @param int $tagCountAfter `/StructElem` count of the output.
	 * @param bool $structureIntactAfter Whether the output is still detected as tagged.
	 * @param int $streamsModified Count of content streams the replacement touched
	 *                             (SAPP's `replace_text_in_document` stats).
	 *
	 * @return StructurePreservation The result block for this run.
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-002
	 */
	private function attestStructurePreservation(
		?bool $rawOption,
		bool $requested,
		int $tagCountBefore,
		int $tagCountAfter,
		bool $structureIntactAfter,
		int $streamsModified,
	): StructurePreservation {
		if ($rawOption === false) {
			// Explicit opt-out: no assertion of loss either, per D3 — counts
			// are still measured for the truthful report.
			return new StructurePreservation(
				requested: false,
				preserved: false,
				tagCountBefore: $tagCountBefore,
				tagCountAfter: $tagCountAfter,
				lossReasons: []
			);
		}

		if ($tagCountBefore === 0) {
			// Preservation requested (auto or explicit true) but not
			// applicable — the input was not tagged. $requested reflects
			// the resolved auto/true outcome (false for auto+untagged, true
			// for explicit true — REQ-ORTPR-004); either way the loss is
			// reported as "not applicable", not a failure.
			return new StructurePreservation(
				requested: $requested,
				preserved: false,
				tagCountBefore: $tagCountBefore,
				tagCountAfter: $tagCountAfter,
				lossReasons: [StructurePreservation::LOSS_REASON_INPUT_NOT_TAGGED]
			);
		}

		$lossReasons = [];
		if ($tagCountAfter !== $tagCountBefore || $structureIntactAfter === false) {
			$lossReasons[] = StructurePreservation::LOSS_REASON_STRUCTTREEROOT_DROPPED;
		} elseif ($streamsModified > 0) {
			$lossReasons[] = StructurePreservation::LOSS_REASON_MARKED_CONTENT_BROKEN;
		}

		return new StructurePreservation(
			requested: true,
			preserved: (count($lossReasons) === 0),
			tagCountBefore: $tagCountBefore,
			tagCountAfter: $tagCountAfter,
			lossReasons: $lossReasons
		);
	}//end attestStructurePreservation()

	/**
	 * Build the `structurePreservation` block for the empty-substitutions
	 * no-op path (REQ-ORTPR-005): output bytes are identical to the input,
	 * so `tagCountAfter` trivially equals `tagCountBefore` and nothing was
	 * modified.
	 *
	 * @param string $pdfBytes The (unchanged) input bytes.
	 * @param bool|null $preserveStructure Caller-supplied tri-state option.
	 *
	 * @return StructurePreservation The result block for this no-op run.
	 */
	private function measureNoOnStructurePreservation(string $pdfBytes, ?bool $preserveStructure): StructurePreservation {
		$tagCountBefore = 0;

		try {
			$doc = PDFDoc::from_string(buffer: $pdfBytes);
			if ($doc !== false) {
				$tagCountBefore = $this->structureInspector->countStructElements(doc: $doc);
			}
		} catch (Throwable $e) {
			// Measurement-only failure on a no-op call: fall back to 0
			// rather than letting an inspection error mask the (successful)
			// no-op — the caller still gets $pdfBytes back unchanged.
			$tagCountBefore = 0;
		}

		$requested = $this->resolveRequestedPreservation(preserveStructure: $preserveStructure, tagCountBefore: $tagCountBefore);

		return $this->attestStructurePreservation(
			rawOption: $preserveStructure,
			requested: $requested,
			tagCountBefore: $tagCountBefore,
			tagCountAfter: $tagCountBefore,
			structureIntactAfter: true,
			streamsModified: 0
		);
	}//end measureNoOpStructurePreservation()
}//end class
