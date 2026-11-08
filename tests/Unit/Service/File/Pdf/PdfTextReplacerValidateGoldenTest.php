<?php

/**
 * Golden test pinning the residual-needle probing of PdfTextReplacer::validateOutput.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Pdf
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Pdf;

use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which needles count as residual (empty, blank, case, whitespace,
 * numeric keys, invalid UTF-8) and the diagnostic the residual warning carries.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer
 */
final class PdfTextReplacerValidateGoldenTest extends TestCase {

	/**
	 * Logged warnings as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Replacer under test.
	 *
	 * @var PdfTextReplacer
	 */
	private PdfTextReplacer $replacer;

	/**
	 * Build the replacer with a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\ddn\sapp\PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		$this->replacer = new PdfTextReplacer($logger);
	}//end setUp()

	/**
	 * Needle edge cases decide residual membership, in substitution order.
	 *
	 * @return void
	 */
	public function testNeedleProbing(): void {
		$pdf = self::buildFixture('De heer Jansen woont sinds 2026 in Amsterdam.');
		$residuals = $this->replacer->validateOutput(
			$pdf,
			[
				'' => '[EMPTY]',
				'   ' => '[BLANK]',
				'jansen' => '[LOWER]',
				'Amsterdam' => '[LOCATION: 1]',
				'heer   Jansen' => '[SPACED]',
				'2026' => '[NUMBER]',
				"Jan\xFFsen" => '[BAD]',
				'Pietersen' => '[ABSENT]',
			],
			[
				'streams_scanned' => 3,
				'streams_modified' => 2,
				'font_encoding_misses' => ['a', 'b'],
				'rejected_substitutions' => ['x'],
			],
			true
		);

		$this->assertSame(['Amsterdam', 'heer   Jansen', '2026'], $residuals);

		$this->assertCount(2, $this->warnings);
		$this->assertSame('[PdfTextReplacer] Needle normalisation failed — probing raw bytes', $this->warnings[0][0]);
		$this->assertSame('validate.normalise', $this->warnings[0][1]['stage']);
		$this->assertSame(6, $this->warnings[0][1]['needle_index']);
		$this->assertSame('[PdfTextReplacer] Partial anonymisation — residual entity text in output', $this->warnings[1][0]);
		$this->assertSame(
			[
				'stage' => 'validate.assert',
				'residual_count' => 3,
				'streams_scanned' => 3,
				'streams_modified' => 2,
				'tj_arrays_modified' => null,
				'subset_font_fallbacks_used' => null,
				'font_encoding_misses' => 2,
				'cid_split_mismatch' => 0,
				'encoding_dict_unhandled' => 0,
				'contents_array_pages' => 0,
				'rejected_substitutions' => 1,
			],
			$this->warnings[1][1]
		);
	}//end testNeedleProbing()

	/**
	 * An invalid-UTF-8 needle present byte-for-byte is reported as residual.
	 *
	 * @return void
	 */
	public function testRawProbeFindsInvalidNeedleBytes(): void {
		$pdf = self::buildFixture('De heer Jansen.');
		$residuals = $this->replacer->validateOutput($pdf, ["Jansen\xFF" => '[A]', 'Jansen' => '[B]']);

		$this->assertSame(['Jansen'], $residuals);
		$this->assertSame(0, $this->warnings[0][1]['needle_index']);
	}//end testRawProbeFindsInvalidNeedleBytes()

	/**
	 * No residual means no residual warning.
	 *
	 * @return void
	 */
	public function testCleanOutputLogsNothing(): void {
		$pdf = self::buildFixture('Aanvraag van [PERSON: 1].');

		$this->assertSame([], $this->replacer->validateOutput($pdf, ['Jansen' => '[PERSON: 1]']));
		$this->assertSame([], $this->warnings);
	}//end testCleanOutputLogsNothing()

	/**
	 * Build a minimal single-page PDF with one text run.
	 *
	 * @param string $bodyText Text shown on the page
	 *
	 * @return string
	 */
	private static function buildFixture(string $bodyText): string {
		$contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(" . $bodyText . ") Tj\nET\n";
		$compressed = (string)gzcompress($contentStream, 6);

		$objects = [
			1 => "<<\n  /Type /Catalog\n  /Pages 2 0 R\n>>",
			2 => "<<\n  /Type /Pages\n  /Kids [ 3 0 R ]\n  /Count 1\n>>",
			3 => "<<\n  /Type /Page\n  /Parent 2 0 R\n  /MediaBox [ 0 0 612 792 ]\n"
				. "  /Resources <<\n    /Font << /F1 5 0 R >>\n    /ProcSet [ /PDF /Text ]\n  >>\n  /Contents 4 0 R\n>>",
			4 => "<<\n  /Length " . strlen($compressed) . "\n  /Filter /FlateDecode\n>>\nstream\n" . $compressed . "\nendstream",
			5 => "<<\n  /Type /Font\n  /Subtype /Type1\n  /BaseFont /Helvetica\n  /Encoding /WinAnsiEncoding\n>>",
		];

		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $oid . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xrefStart = strlen($pdf);
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf . "trailer\n<<\n  /Size 6\n  /Root 1 0 R\n>>\nstartxref\n" . $xrefStart . "\n%%EOF\n";
	}//end buildFixture()
}//end class
