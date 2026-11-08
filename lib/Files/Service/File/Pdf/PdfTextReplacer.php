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
 *     (residual entity text is logged PII-free and returned to the caller,
 *     which still writes the file; a strict run refuses only when the output
 *     cannot be re-read at all)
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

namespace OCA\OpenRegister\Files\Service\File\Pdf;

use OCA\OpenRegister\Kernel\Contracts\StructurePreservation;
use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use Psr\Log\LoggerInterface;
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
 *     the extracted text, and returns the residual needles to the caller.
 *     A strict run (entity anonymisation) whose output cannot be re-read
 *     is refused; a lenient run returns the unchecked output.
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
	 * Text extractor used to re-read the output for residual entity text.
	 *
	 * @var PdfExtractor
	 */
	private readonly PdfExtractor $pdfExtractor;

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
		$this->pdfExtractor = new PdfExtractor(logger: $logger);
	}//end __construct()

	/**
	 * Replace entity text in a PDF byte stream and emit a sanitised output.
	 *
	 * Runs the full pipeline: load → replace → re-serialise → validate.
	 * Residual entity text does not fail the run: the output is returned and
	 * the residual needles land in $residualEntities. A strict run throws
	 * when the output cannot be re-read for that check.
	 *
	 * @param string $pdfBytes Raw input PDF bytes.
	 * @param array $substitutions Map: entity-text => placeholder
	 *                             (e.g. ['Jan Jansen' =>
	 *                             '[PERSON: 7]']).
	 * @param bool $strict Forwarded to {@see validateOutput}: when
	 *                     true, an output that cannot be re-read is refused.
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
	 * @throws PdfAnonymisationException On any pipeline failure, and in a strict
	 *                                   run when the output cannot be re-read.
	 *
	 * @phpstan-param array<string, string> $substitutions
	 * @psalm-param   array<string, string> $substitutions
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   $strict forwards the strict vs lenient validation policy.
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

		$substitutions = self::longestFirst(substitutions: $substitutions);
		$doc = $this->loadDocument(pdfBytes: $pdfBytes);

		// Measure BEFORE any mutation, reusing this same loaded $doc — no
		// second parse (REQ-ORTPR-001; design.md "Risks" — the extra-parse
		// cost is avoided by inspecting the object model already in memory).
		$tagCountBefore = $this->structureInspector->countStructElements(doc: $doc);
		$stats = $this->replaceText(doc: $doc, substitutions: $substitutions);

		// Re-measure AFTER text replacement, on the SAME in-memory $doc
		// (`replace_text_in_document` mutates content streams only; the
		// structure-tree objects — `/StructTreeRoot`, `/MarkInfo`,
		// `/StructElem` — are not content streams, so this reflects what
		// `to_pdf_file_s(rebuild: true)` will serialise). Never a silent
		// flatten: any loss surfaces via $structureResult regardless of
		// $preserveStructure (REQ-ORTPR-002).
		$measurement = new StructureMeasurement(
			rawOption: $preserveStructure,
			tagCountBefore: $tagCountBefore,
			tagCountAfter: $this->structureInspector->countStructElements(doc: $doc),
			structureIntactAfter: $this->structureInspector->isTagged(doc: $doc)
		);

		$outputBytes = $this->serialise(doc: $doc);

		// Attest the conservative `preserved` rule (design.md D1) and build
		// the contracted result block (REQ-ORTPR-002/003). This never
		// changes $outputBytes — preservation is measurement + honest
		// degradation, not a repair the current stack can perform.
		$structureResult = $measurement->attest(streamsModified: (int)($stats['streams_modified'] ?? 0));

		// Validation: re-extract the output and collect any residual
		// substitution-map keys. The residual needles go back to the caller,
		// which writes the file and surfaces a warning.
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
	 * Order a substitution map longest needle first.
	 *
	 * SAPP iterates the map in PHP key-insertion order (linear matcher and
	 * the cross-operator / cross-block / cross-line passes all `foreach` it
	 * directly; the cross-line claimed-range guard explicitly assumes
	 * longest-first, see Conduction/sapp PR #1). Re-sorting here makes the
	 * overlap guarantee hold for every caller and survive future SAPP bumps.
	 * Length desc, bytewise asc tie-break for deterministic output. The
	 * comparator params are untyped: PHP coerces purely-numeric needle text
	 * ("2026", a spaceless BSN) to INT array keys, which would fatal a
	 * string-typed closure.
	 *
	 * @param array<string, string> $substitutions Map: entity-text => placeholder.
	 *
	 * @return array<string, string> The same map, longest needle first.
	 */
	private static function longestFirst(array $substitutions): array {
		uksort(
			$substitutions,
			static function ($left, $right): int {
				$left = (string)$left;
				$right = (string)$right;
				return [mb_strlen($right), $left] <=> [mb_strlen($left), $right];
			}
		);

		return $substitutions;
	}//end longestFirst()

	/**
	 * Load PDF bytes into a SAPP document.
	 *
	 * @param string $pdfBytes Raw input PDF bytes.
	 *
	 * @return PDFDoc The loaded document.
	 *
	 * @throws PdfAnonymisationException When SAPP cannot load the bytes.
	 */
	private function loadDocument(string $pdfBytes): PDFDoc {
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

		return $doc;
	}//end loadDocument()

	/**
	 * Replace the substitution map's needles in every content stream.
	 *
	 * @param PDFDoc $doc The loaded document, mutated in place.
	 * @param array<string, string> $substitutions Map: entity-text => placeholder, longest first.
	 *
	 * @return array<string, mixed> SAPP's replacement statistics.
	 *
	 * @throws PdfAnonymisationException When SAPP raises during replacement.
	 */
	private function replaceText(PDFDoc $doc, array $substitutions): array {
		try {
			return $doc->replace_text_in_document(substitutions: $substitutions);
		} catch (Throwable $e) {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'SAPP replace_text_in_document raised an exception',
				diagnostic: ['stage' => 'replace'],
				previous: $e
			);
		}
	}//end replaceText()

	/**
	 * Serialise the document with rebuild=true per the SAPP contract.
	 *
	 * Incremental mode produces an unopenable PDF once _pdf_objects is
	 * populated by replace_text_in_document. `to_pdf_file_s()` is the
	 * string-returning sibling of `to_pdf_file_b()` (calls `get_raw()` on the
	 * underlying Buffer); `Buffer::__toString()` returns a `debug_var()`
	 * dump, NOT the raw bytes.
	 *
	 * @param PDFDoc $doc The replaced document.
	 *
	 * @return string The PDF bytes.
	 *
	 * @throws PdfAnonymisationException When SAPP returns nothing.
	 */
	private function serialise(PDFDoc $doc): string {
		$serialised = $doc->to_pdf_file_s(rebuild: true);
		if ($serialised === false || $serialised === '') {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'SAPP to_pdf_file_s returned false/empty',
				diagnostic: ['stage' => 'serialise']
			);
		}

		return $serialised;
	}//end serialise()

	/**
	 * Re-extract the output PDF via smalot/pdfparser and return the
	 * substitution-map keys that survive in it.
	 *
	 * Best-effort in both modes (pdf-anonymisation spec): residual entity
	 * text is logged as a PII-free warning and returned, and the caller still
	 * writes the file. When the parser cannot re-read the output, nothing can
	 * be checked: a lenient run logs that and returns an empty list, a strict
	 * run throws `REASON_VALIDATION_FAILED` so no unchecked file is written.
	 *
	 * @param string $outputBytes Serialised output PDF bytes.
	 * @param array $substitutions The original substitution map.
	 * @param array $replaceStats Stats returned by SAPP's replace API
	 *                            (kept in the diagnostic surface
	 *                            for ops review).
	 * @param bool $strict When true (the entity-anonymisation flow), an
	 *                     output the parser cannot re-read is refused;
	 *                     when false (ad-hoc replace) it is returned
	 *                     unchecked with a logged warning.
	 *
	 * @return string[] The substitution-map keys found in the output.
	 *
	 * @throws PdfAnonymisationException When $strict and the output cannot be re-read.
	 *
	 * @phpstan-param array<string, string> $substitutions
	 * @phpstan-param array<string, mixed>  $replaceStats
	 * @psalm-param   array<string, string> $substitutions
	 * @psalm-param   array<string, mixed>  $replaceStats
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $strict selects strict (entity anonymisation) vs lenient (ad-hoc) behaviour.
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function validateOutput(string $outputBytes, array $substitutions, array $replaceStats = [], bool $strict = false): array {
		$extracted = $this->reExtract(outputBytes: $outputBytes, replaceStats: $replaceStats);
		if ($extracted === null) {
			$this->refuseUncheckedOutputWhenStrict(strict: $strict, outputBytes: $outputBytes);
			return [];
		}

		$residual = $this->findResidualNeedles(
			extracted: $extracted,
			normalisedExtracted: $this->normaliseHaystack(extracted: $extracted),
			substitutions: $substitutions
		);

		if (count($residual) > 0) {
			$this->logResidual(residualCount: count($residual), replaceStats: $replaceStats);
		}

		// Best-effort: the anonymisation flow writes the output and surfaces
		// the residual entities so the operator can iterate (manual
		// entities, skip unselected occurrences). Logs stay PII-free per
		// ADR-005; the authenticated anonymise response carries the values
		// for the review UI.
		return $residual;
	}//end validateOutput()

	/**
	 * Whitespace-normalise the re-extracted haystack.
	 *
	 * Entity text that the PDF splits across line breaks ("14 mei\n2026") is
	 * still detected — smalot re-extraction inserts the line structure, but
	 * the entity is semantically present either way. Needles get the same
	 * treatment so both sides compare in collapsed-space form.
	 *
	 * `preg_replace` with the /u modifier returns NULL on invalid UTF-8
	 * (PREG_BAD_UTF8_ERROR) — a realistic mode here, since the encoding gaps
	 * SAPP tracks (font_encoding_misses, cid_split_mismatch,
	 * encoding_dict_unhandled) make smalot emit raw font-encoded bytes. A
	 * silent ""-fallback would make every probe miss and strict mode pass
	 * unaudited output — the documents most at risk of residual PII are
	 * exactly the ones that would slip through. Both modes fall back to the
	 * un-normalised haystack instead, which keeps the pre-normalisation
	 * detection coverage.
	 *
	 * @param string $extracted Re-extracted output text.
	 *
	 * @return string The normalised haystack, or $extracted when normalisation fails.
	 */
	private function normaliseHaystack(string $extracted): string {
		$normalisedExtracted = preg_replace('/\s+/u', ' ', $extracted);
		if ($normalisedExtracted === null) {
			$this->logger->warning(
				message: '[PdfTextReplacer] Haystack normalisation failed — falling back to un-normalised extraction',
				context: [
					'stage' => 'validate.normalise',
					'preg_error' => preg_last_error_msg(),
				]
			);
			return $extracted;
		}

		return $normalisedExtracted;
	}//end normaliseHaystack()

	/**
	 * Collect the substitution-map keys still present in the output.
	 *
	 * @param string $extracted           Raw re-extracted output text.
	 * @param string $normalisedExtracted Whitespace-normalised output text.
	 * @param array  $substitutions       The original substitution map.
	 *
	 * @return string[] The residual needles, in substitution order.
	 *
	 * @phpstan-param array<string, string> $substitutions
	 * @psalm-param   array<string, string> $substitutions
	 */
	private function findResidualNeedles(string $extracted, string $normalisedExtracted, array $substitutions): array {
		$residual = [];
		foreach (array_keys($substitutions) as $needleIndex => $needle) {
			if ($needle === '') {
				continue;
			}

			$rawNeedle = (string)$needle;
			if ($this->needleIsResidual(
				needleIndex: $needleIndex,
				rawNeedle: $rawNeedle,
				extracted: $extracted,
				normalisedExtracted: $normalisedExtracted
			) === true
			) {
				$residual[] = $rawNeedle;
			}
		}

		return $residual;
	}//end findResidualNeedles()

	/**
	 * Decide whether one needle is still present in the output.
	 *
	 * @param int    $needleIndex         Position of the needle in the map.
	 * @param string $rawNeedle           The needle as given.
	 * @param string $extracted           Raw re-extracted output text.
	 * @param string $normalisedExtracted Whitespace-normalised output text.
	 *
	 * @return bool
	 */
	private function needleIsResidual(int $needleIndex, string $rawNeedle, string $extracted, string $normalisedExtracted): bool {
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
			return strpos($extracted, $rawNeedle) !== false;
		}

		$normalisedNeedle = trim($normalisedNeedle);
		if ($normalisedNeedle === '') {
			return false;
		}

		// Case-SENSITIVE, multibyte-safe substring check via `mb_strpos`
		// — mirrors the replacement engine's guarantee. SAPP replaces
		// exact-case needles only, so a case-insensitive probe here
		// flagged text that was never a detected entity (e.g. the
		// lowercase domain in "www.amsterdam.nl" tripping on the
		// LOCATION needle "Amsterdam") and failed runs that were in
		// fact fully anonymised.
		return mb_strpos($normalisedExtracted, $normalisedNeedle) !== false;
	}//end needleIsResidual()

	/**
	 * Log the PII-free residual warning.
	 *
	 * PII-redacted log line per ADR-005. NEVER include the residual entity
	 * text in logs — only the count + structural counters. The audit trail
	 * (per ADR-022) handles the values.
	 *
	 * @param int   $residualCount Number of residual needles.
	 * @param array $replaceStats  Stats returned by SAPP's replace API.
	 *
	 * @return void
	 *
	 * @phpstan-param array<string, mixed> $replaceStats
	 * @psalm-param   array<string, mixed> $replaceStats
	 */
	private function logResidual(int $residualCount, array $replaceStats): void {
		$this->logger->warning(
			message: '[PdfTextReplacer] Partial anonymisation — residual entity text in output',
			context: [
				'stage' => 'validate.assert',
				'residual_count' => $residualCount,
				'streams_scanned' => ($replaceStats['streams_scanned'] ?? null),
				'streams_modified' => ($replaceStats['streams_modified'] ?? null),
				'tj_arrays_modified' => ($replaceStats['tj_arrays_modified'] ?? null),
				'subset_font_fallbacks_used' => ($replaceStats['subset_font_fallbacks_used'] ?? null),
				'font_encoding_misses' => count(($replaceStats['font_encoding_misses'] ?? [])),
				'cid_split_mismatch' => count(($replaceStats['cid_split_mismatch'] ?? [])),
				'encoding_dict_unhandled' => count(($replaceStats['encoding_dict_unhandled'] ?? [])),
				'contents_array_pages' => count(($replaceStats['contents_array_pages'] ?? [])),
				'rejected_substitutions' => count(($replaceStats['rejected_substitutions'] ?? [])),
			]
		);
	}//end logResidual()

	/**
	 * Re-extract the text of the output PDF for the residual check.
	 *
	 * A parser failure is logged structurally (no document or needle text).
	 *
	 * @param string $outputBytes  Serialised output PDF bytes.
	 * @param array  $replaceStats Stats returned by SAPP's replace API.
	 *
	 * @return string|null The extracted text, or null when the parser cannot read the output.
	 *
	 * @phpstan-param array<string, mixed> $replaceStats
	 * @psalm-param   array<string, mixed> $replaceStats
	 */
	private function reExtract(string $outputBytes, array $replaceStats): ?string {
		try {
			return $this->pdfExtractor->extractText(pdfBytes: $outputBytes);
		} catch (Throwable $e) {
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

			return null;
		}
	}//end reExtract()

	/**
	 * Refuse an output whose residual check could not run, in a strict run.
	 *
	 * A strict run (entity anonymisation) must not write an `_anonymized`
	 * file nobody could check. The refusal carries counts only.
	 *
	 * @param bool   $strict      Whether this is a strict run.
	 * @param string $outputBytes Serialised output PDF bytes.
	 *
	 * @return void
	 *
	 * @throws PdfAnonymisationException When $strict.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $strict selects strict (entity anonymisation) vs lenient (ad-hoc) behaviour.
	 */
	private function refuseUncheckedOutputWhenStrict(bool $strict, string $outputBytes): void {
		if ($strict === false) {
			return;
		}

		throw new PdfAnonymisationException(
			reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
			message: 'PDF anonymisation refused: the output could not be re-read to check for entity text, so no file was written',
			diagnostic: [
				'stage' => 'validate.extract',
				'output_bytes' => strlen($outputBytes),
			]
		);
	}//end refuseUncheckedOutputWhenStrict()

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

		$measurement = new StructureMeasurement(
			rawOption: $preserveStructure,
			tagCountBefore: $tagCountBefore,
			tagCountAfter: $tagCountBefore,
			structureIntactAfter: true
		);

		return $measurement->attest(streamsModified: 0);
	}//end measureNoOpStructurePreservation()

	/**
	 * Strip the /Info dictionary and XMP metadata from PDF bytes.
	 *
	 * Fails closed: when the PDF library cannot reparse or reserialise the
	 * bytes, the unsanitised input (which still carries the original
	 * document metadata) is never returned.
	 *
	 * @param string $pdfBytes PDF bytes, typically the output of {@see replaceInPdf()}.
	 *
	 * @return string The re-serialised PDF without document metadata.
	 *
	 * @throws PdfAnonymisationException When the bytes cannot be reparsed or the serialised result is empty.
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function sanitizeMetadata(string $pdfBytes): string {
		$doc = PDFDoc::from_string(buffer: $pdfBytes);
		if ($doc === false) {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'SAPP failed to reparse replaced PDF for metadata sanitisation',
				diagnostic: ['stage' => 'sanitize.reparse']
			);
		}

		(new PdfMetadataSanitizer(logger: $this->logger))->sanitize(doc: $doc);
		$serialised = $doc->to_pdf_file_s(rebuild: true);
		if ($serialised === false || $serialised === '') {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'SAPP serialise after metadata sanitisation returned empty',
				diagnostic: ['stage' => 'sanitize.serialise']
			);
		}

		return $serialised;
	}//end sanitizeMetadata()
}//end class
