<?php

/**
 * PdfTextReplacerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer}
 * covering the replace → validate → fail-closed pipeline plus the
 * adjacent-duplicate placeholder collapse helper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
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

namespace Unit\Service\File\Pdf;

use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Service\File\Pdf\PdfStructureInspector;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Service\File\Pdf\StructurePreservation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see PdfTextReplacer}.
 *
 * Fixtures are synthesised in-test (per the change spec — no binary
 * blobs checked into the repo). The minimal one-page fixture follows
 * the SAPP PoC shape: one FlateDecode-compressed content stream, one
 * Helvetica/WinAnsi font, one Tj operator emitting the needle text.
 */
class PdfTextReplacerTest extends TestCase {

	/**
	 * Mock logger used to capture diagnostic surface emissions.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * System-under-test instance, recreated per test in setUp.
	 *
	 * @var PdfTextReplacer
	 */
	private PdfTextReplacer $replacer;

	/**
	 * Reset the system-under-test before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// PdfTextReplacer is backed by the optional `ddn/sapp` library
		// (fetched from a Codeberg VCS repo). Some deploy/CI containers
		// ship without it; skip rather than error so the gateable subset
		// stays honest where the dependency is absent.
		if (class_exists(\ddn\sapp\PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->replacer = new PdfTextReplacer(logger: $this->logger);
	}//end setUp()

	/**
	 * Build a minimal one-page PDF containing a single Tj operator
	 * emitting `$bodyText` in Helvetica/WinAnsi.
	 *
	 * @param string $bodyText The body text to embed in the Tj operand.
	 *
	 * @return string The synthesised PDF bytes.
	 */
	private static function buildFixture(string $bodyText): string {
		$contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(" . $bodyText . ") Tj\nET\n";
		$compressed = gzcompress($contentStream, 6);
		if ($compressed === false) {
			throw new \RuntimeException('gzcompress failed in fixture builder');
		}

		$obj = static function (int $oid, string $body): string {
			return $oid . " 0 obj\n" . $body . "\nendobj\n";
		};

		$obj1 = $obj(1, "<<\n  /Type /Catalog\n  /Pages 2 0 R\n>>");
		$obj2 = $obj(2, "<<\n  /Type /Pages\n  /Kids [ 3 0 R ]\n  /Count 1\n>>");
		$obj3 = $obj(
			3,
			"<<\n  /Type /Page\n  /Parent 2 0 R\n"
			. "  /MediaBox [ 0 0 612 792 ]\n"
			. "  /Resources <<\n    /Font << /F1 5 0 R >>\n    /ProcSet [ /PDF /Text ]\n  >>\n"
			. "  /Contents 4 0 R\n>>"
		);
		$obj4 = $obj(
			4,
			"<<\n  /Length " . strlen($compressed) . "\n  /Filter /FlateDecode\n>>\n"
			. "stream\n" . $compressed . "\nendstream"
		);
		$obj5 = $obj(
			5,
			"<<\n  /Type /Font\n  /Subtype /Type1\n  /BaseFont /Helvetica\n  /Encoding /WinAnsiEncoding\n>>"
		);

		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ([1 => $obj1, 2 => $obj2, 3 => $obj3, 4 => $obj4, 5 => $obj5] as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $body;
		}

		$xrefStart = strlen($pdf);
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";
		foreach ([1, 2, 3, 4, 5] as $oid) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$oid]);
		}

		$pdf .= "trailer\n<<\n  /Size 6\n  /Root 1 0 R\n>>\nstartxref\n" . $xrefStart . "\n%%EOF\n";

		return $pdf;
	}//end buildFixture()

	/**
	 * Build a minimal one-page PDF carrying a `/StructTreeRoot`,
	 * `/MarkInfo << /Marked true >>` and `$structElemCount` `/StructElem`
	 * objects (tag-preserving-redaction fixture shape — mirrors
	 * {@see \Unit\Service\File\Pdf\PdfStructureInspectorTest::buildTaggedFixture()}).
	 *
	 * @param string $bodyText The body text to embed in the Tj operand.
	 * @param int $structElemCount Number of `/StructElem` objects to emit.
	 *
	 * @return string The synthesised PDF bytes.
	 */
	private static function buildTaggedFixture(string $bodyText, int $structElemCount = 3): string {
		$contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(" . $bodyText . ") Tj\nET\n";
		$compressed = gzcompress($contentStream, 6);
		if ($compressed === false) {
			throw new \RuntimeException('gzcompress failed in fixture builder');
		}

		$obj = static function (int $oid, string $body): string {
			return $oid . " 0 obj\n" . $body . "\nendobj\n";
		};

		$structElemRefs = [];
		for ($i = 0; $i < $structElemCount; $i++) {
			$structElemRefs[] = (8 + $i) . ' 0 R';
		}

		$objects = [];
		$objects[1] = $obj(1, "<<\n  /Type /Catalog\n  /Pages 2 0 R\n  /StructTreeRoot 6 0 R\n  /MarkInfo 7 0 R\n>>");
		$objects[2] = $obj(2, "<<\n  /Type /Pages\n  /Kids [ 3 0 R ]\n  /Count 1\n>>");
		$objects[3] = $obj(
			3,
			"<<\n  /Type /Page\n  /Parent 2 0 R\n"
			. "  /MediaBox [ 0 0 612 792 ]\n"
			. "  /Resources <<\n    /Font << /F1 5 0 R >>\n    /ProcSet [ /PDF /Text ]\n  >>\n"
			. "  /Contents 4 0 R\n  /StructParents 0\n>>"
		);
		$objects[4] = $obj(
			4,
			"<<\n  /Length " . strlen($compressed) . "\n  /Filter /FlateDecode\n>>\n"
			. "stream\n" . $compressed . "\nendstream"
		);
		$objects[5] = $obj(
			5,
			"<<\n  /Type /Font\n  /Subtype /Type1\n  /BaseFont /Helvetica\n  /Encoding /WinAnsiEncoding\n>>"
		);
		$objects[6] = $obj(6, "<<\n  /Type /StructTreeRoot\n  /K [ " . implode(' ', $structElemRefs) . " ]\n>>");
		$objects[7] = $obj(7, "<<\n  /Marked true\n>>");

		for ($i = 0; $i < $structElemCount; $i++) {
			$oid = (8 + $i);
			$objects[$oid] = $obj($oid, "<<\n  /Type /StructElem\n  /S /P\n  /P 6 0 R\n  /Pg 3 0 R\n>>");
		}

		ksort($objects);

		$pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $body;
		}

		$maxOid = max(array_keys($objects));
		$xrefStart = strlen($pdf);
		$pdf .= "xref\n0 " . ($maxOid + 1) . "\n0000000000 65535 f \n";
		for ($oid = 1; $oid <= $maxOid; $oid++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$oid]);
		}

		$pdf .= "trailer\n<<\n  /Size " . ($maxOid + 1) . "\n  /Root 1 0 R\n>>\nstartxref\n" . $xrefStart . "\n%%EOF\n";

		return $pdf;
	}//end buildTaggedFixture()

	/**
	 * Happy path: a fixture containing "Jan Jansen" with a clean
	 * substitution map produces an output that re-extracts to the
	 * placeholder, contains no residual entity text, and is a valid PDF.
	 *
	 * @return void
	 */
	public function testReplaceInPdfHappyPath(): void {
		$pdf = self::buildFixture(bodyText: 'Aanvraag van Jan Jansen voor het loket.');

		$output = $this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]']
		);

		// Output MUST be a valid PDF (starts with %PDF-, ends with %%EOF).
		$this->assertStringStartsWith(prefix: '%PDF-', string: $output);
		$this->assertStringEndsWith(suffix: "%%EOF\n", string: $output);

		// Re-extract via smalot/pdfparser and assert the placeholder is
		// present and the original entity text is gone.
		$parser = new \Smalot\PdfParser\Parser();
		$extracted = $parser->parseContent($output)->getText();

		$this->assertStringContainsString(needle: '[PERSON: 1]', haystack: $extracted);
		$this->assertStringNotContainsString(needle: 'Jan Jansen', haystack: $extracted);
	}//end testReplaceInPdfHappyPath()

	/**
	 * Empty substitutions map → input returned unchanged (early-exit).
	 *
	 * @return void
	 */
	public function testReplaceInPdfEmptySubstitutionsIsNoOp(): void {
		$pdf = self::buildFixture(bodyText: 'Aanvraag van Jan Jansen voor het loket.');
		$output = $this->replacer->replaceInPdf(pdfBytes: $pdf, substitutions: []);

		$this->assertSame(expected: $pdf, actual: $output);
	}//end testReplaceInPdfEmptySubstitutionsIsNoOp()

	/**
	 * Lenient default ($strict = false, ad-hoc replace — docx parity): when
	 * residual entity text remains the gate MUST emit a PII-redacted warning
	 * to the logger and MUST NOT throw — the partial PDF reaches the caller.
	 *
	 * @return void
	 */
	public function testValidateOutputLogsWarningOnResidualWithoutThrowing(): void {
		$pdfContainingNeedle = self::buildFixture(bodyText: 'De heer Jansen bezocht het loket.');

		$this->logger
			->expects(matcher: self::once())
			->method(constraint: 'warning')
			->with(
				self::matchesRegularExpression('/Partial anonymisation/i'),
				self::callback(callback: function (array $context): bool {
					// PII redaction: context MUST NOT contain the actual entity text.
					if (strpos((string)json_encode($context), 'Jansen') !== false) {
						return false;
					}

					return ($context['residual_count'] ?? 0) > 0
						&& ($context['stage'] ?? null) === 'validate.assert';
				})
			);

		$this->replacer->validateOutput(
			outputBytes: $pdfContainingNeedle,
			substitutions: ['Jansen' => '[PERSON: 1]']
		);
	}//end testValidateOutputLogsWarningOnResidualWithoutThrowing()

	/**
	 * Best-effort policy ($strict = true, entity anonymisation): residual entity
	 * text no longer fails closed. validateOutput RETURNS the residual needles
	 * (and logs a PII-free warning) so the caller can still produce the file and
	 * surface a warning for the operator to iterate on. (Previously this threw
	 * `PdfAnonymisationException(REASON_VALIDATION_FAILED)`.)
	 *
	 * @return void
	 */
	public function testValidateOutputReturnsResidualsWhenStrict(): void {
		$pdfContainingNeedle = self::buildFixture(bodyText: 'De heer Jansen bezocht het loket.');

		$residuals = $this->replacer->validateOutput(
			outputBytes: $pdfContainingNeedle,
			substitutions: ['Jansen' => '[PERSON: 1]'],
			replaceStats: [],
			strict: true
		);

		// The unredacted needle is reported back (no exception thrown).
		self::assertContains(needle: 'Jansen', haystack: $residuals);
	}//end testValidateOutputReturnsResidualsWhenStrict()

	/**
	 * Validation gate passes silently when the PDF contains only the
	 * placeholder and no residual entity text.
	 *
	 * @return void
	 */
	public function testValidateOutputPassesWhenClean(): void {
		$pdf = self::buildFixture(bodyText: 'Aanvraag van [PERSON: 1] voor het loket.');

		// No exception expected; no residual needles returned.
		$residuals = $this->replacer->validateOutput(
			outputBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]']
		);

		self::assertSame(expected: [], actual: $residuals);
	}//end testValidateOutputPassesWhenClean()

	/**
	 * Adjacent-duplicate placeholder collapse — covers the variant-driven
	 * split case (`[PERSON: 1] [PERSON: 1]` → `[PERSON: 1]`).
	 *
	 * @return void
	 */
	public function testCollapseAdjacentDuplicates(): void {
		$input = 'Aanvraag van [PERSON: 1] [PERSON: 1] voor het loket.';
		$expected = 'Aanvraag van [PERSON: 1] voor het loket.';

		$this->assertSame(
			expected: $expected,
			actual: PdfTextReplacer::collapseAdjacentDuplicatePlaceholders(text: $input)
		);
	}//end testCollapseAdjacentDuplicates()

	/**
	 * Collapse handles runs of 3+ duplicates and varied separators.
	 *
	 * The pattern matches `[PERSON: 1]\t[PERSON: 1]-_[PERSON: 1]` (three
	 * duplicates separated by tab + hyphen + underscore) and replaces it
	 * with a single `[PERSON: 1]`. The leading "Mr " and trailing " said
	 * hi." are outside the match and survive unchanged.
	 *
	 * @return void
	 */
	public function testCollapseAdjacentDuplicatesHandlesVariedSeparators(): void {
		$input = "Mr [PERSON: 1]\t[PERSON: 1]-_[PERSON: 1] said hi.";
		$expected = 'Mr [PERSON: 1] said hi.';

		$this->assertSame(
			expected: $expected,
			actual: PdfTextReplacer::collapseAdjacentDuplicatePlaceholders(text: $input)
		);
	}//end testCollapseAdjacentDuplicatesHandlesVariedSeparators()

	/**
	 * Collapse leaves DIFFERENT adjacent placeholders alone.
	 *
	 * @return void
	 */
	public function testCollapseAdjacentDuplicatesLeavesDifferentPlaceholdersAlone(): void {
		$input = 'Both [PERSON: 1] [PERSON: 2] attended.';
		$this->assertSame(
			expected: $input,
			actual: PdfTextReplacer::collapseAdjacentDuplicatePlaceholders(text: $input)
		);
	}//end testCollapseAdjacentDuplicatesLeavesDifferentPlaceholdersAlone()

	/**
	 * Tagged input whose redaction does not touch any content stream (the
	 * substitution needle is absent from the fixture) — SAPP has zero
	 * marked-content awareness, so this is the only case the conservative
	 * rule can honestly attest as `preserved: true`.
	 *
	 * @return void
	 */
	public function testTaggedPreservationAttested(): void {
		$pdf = self::buildTaggedFixture(bodyText: 'Aanvraag met tags.', structElemCount: 4);

		$structureResult = null;
		$this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['NietAanwezigeNaam' => '[PERSON: 1]'],
			preserveStructure: true,
			structureResult: $structureResult
		);

		self::assertInstanceOf(StructurePreservation::class, $structureResult);
		self::assertTrue($structureResult->requested);
		self::assertTrue($structureResult->preserved);
		self::assertSame(4, $structureResult->tagCountBefore);
		self::assertSame(4, $structureResult->tagCountAfter);
		self::assertSame([], $structureResult->lossReasons);
	}//end testTaggedPreservationAttested()

	/**
	 * Tagged input whose redaction DOES mutate content-stream text — the
	 * tag→content correspondence can no longer be guaranteed, so the engine
	 * reports the loss explicitly instead of silently flattening.
	 *
	 * @return void
	 */
	public function testTaggedLossIsReportedNotSilent(): void {
		$pdf = self::buildTaggedFixture(bodyText: 'Aanvraag van Jan Jansen.', structElemCount: 2);

		$structureResult = null;
		$this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]'],
			preserveStructure: true,
			structureResult: $structureResult
		);

		self::assertInstanceOf(StructurePreservation::class, $structureResult);
		self::assertTrue($structureResult->requested);
		self::assertFalse($structureResult->preserved);
		self::assertSame(2, $structureResult->tagCountBefore);
		self::assertSame(2, $structureResult->tagCountAfter);
		self::assertContains(StructurePreservation::LOSS_REASON_MARKED_CONTENT_BROKEN, $structureResult->lossReasons);
	}//end testTaggedLossIsReportedNotSilent()

	/**
	 * Structure-tree loss on the SAPP rebuild is surfaced, not hidden.
	 *
	 * Real SAPP rebuilds are comprehensive (walk every object oid in the
	 * document, so a well-formed structure tree naturally survives) — this
	 * degradation mode is exercised via an injected {@see PdfStructureInspector}
	 * double that reports the tree gone on the after-measurement, isolating
	 * the attestation branch from SAPP's own (already-tested) rebuild
	 * behaviour.
	 *
	 * @return void
	 */
	public function testStructTreeDroppedIsReported(): void {
		// countStructElements is called twice (before + after the in-place
		// mutation); both return the SAME count (3) so the drop is detected
		// via the isTagged()-after signal alone — pinning the "StructTreeRoot
		// survived" leg of the conservative rule independently of the count
		// leg (design.md D1's third gate condition).
		$inspector = $this->createMock(originalClassName: PdfStructureInspector::class);
		$inspector->expects(self::exactly(2))
			->method('countStructElements')
			->willReturn(3);
		$inspector->expects(self::once())
			->method('isTagged')
			->willReturn(false);

		$replacer = new PdfTextReplacer(logger: $this->logger, structureInspector: $inspector);

		$pdf = self::buildTaggedFixture(bodyText: 'Aanvraag met tags.', structElemCount: 3);

		$structureResult = null;
		$replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Onbekend' => '[PERSON: 1]'],
			preserveStructure: true,
			structureResult: $structureResult
		);

		self::assertInstanceOf(StructurePreservation::class, $structureResult);
		self::assertFalse($structureResult->preserved);
		self::assertContains(StructurePreservation::LOSS_REASON_STRUCTTREEROOT_DROPPED, $structureResult->lossReasons);
	}//end testStructTreeDroppedIsReported()

	/**
	 * Default (absent `preserveStructure`) resolves to auto, which preserves
	 * a tagged input without an explicit opt-in.
	 *
	 * @return void
	 */
	public function testAutoPreservesTaggedByDefault(): void {
		$pdf = self::buildTaggedFixture(bodyText: 'Aanvraag met tags.', structElemCount: 1);

		$structureResult = null;
		$this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Onbekend' => '[PERSON: 1]'],
			structureResult: $structureResult
		);

		self::assertInstanceOf(StructurePreservation::class, $structureResult);
		self::assertTrue($structureResult->requested);
	}//end testAutoPreservesTaggedByDefault()

	/**
	 * Explicit `preserveStructure: false` skips preservation entirely but
	 * still measures and reports the tag count.
	 *
	 * @return void
	 */
	public function testExplicitFalseSkipsButMeasures(): void {
		$pdf = self::buildTaggedFixture(bodyText: 'Aanvraag van Jan Jansen.', structElemCount: 3);

		$structureResult = null;
		$this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]'],
			preserveStructure: false,
			structureResult: $structureResult
		);

		self::assertInstanceOf(StructurePreservation::class, $structureResult);
		self::assertFalse($structureResult->requested);
		self::assertFalse($structureResult->preserved);
		self::assertSame([], $structureResult->lossReasons);
		self::assertSame(3, $structureResult->tagCountBefore);
	}//end testExplicitFalseSkipsButMeasures()

	/**
	 * An untagged input redacted with preservation requested (default auto)
	 * reports zero counts and `input-not-tagged` — not applicable, not a
	 * failure.
	 *
	 * @return void
	 */
	public function testUntaggedReportsNotApplicable(): void {
		$pdf = self::buildFixture(bodyText: 'Aanvraag van Jan Jansen voor het loket.');

		$structureResult = null;
		$this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]'],
			structureResult: $structureResult
		);

		self::assertInstanceOf(StructurePreservation::class, $structureResult);
		self::assertSame(0, $structureResult->tagCountBefore);
		self::assertSame(0, $structureResult->tagCountAfter);
		self::assertFalse($structureResult->preserved);
		self::assertContains(StructurePreservation::LOSS_REASON_INPUT_NOT_TAGGED, $structureResult->lossReasons);
	}//end testUntaggedReportsNotApplicable()

	/**
	 * Byte-stability guard (REQ-ORTPR-005): introducing structure
	 * preservation MUST NOT change the redacted output of an untagged PDF —
	 * the produced bytes are identical regardless of `$preserveStructure`.
	 *
	 * @return void
	 */
	public function testUntaggedOutputByteStable(): void {
		$pdf = self::buildFixture(bodyText: 'Aanvraag van Jan Jansen voor het loket.');

		$outputWithoutOption = $this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]']
		);

		$outputExplicitFalse = $this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]'],
			preserveStructure: false
		);

		$outputExplicitTrue = $this->replacer->replaceInPdf(
			pdfBytes: $pdf,
			substitutions: ['Jan Jansen' => '[PERSON: 1]'],
			preserveStructure: true
		);

		self::assertSame($outputWithoutOption, $outputExplicitFalse);
		self::assertSame($outputWithoutOption, $outputExplicitTrue);
	}//end testUntaggedOutputByteStable()
}//end class
