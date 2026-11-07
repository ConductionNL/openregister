<?php

/**
 * PdfStructureInspectorGoldenTest
 *
 * Golden tests that pin the current behaviour of
 * {@see \OCA\OpenRegister\Files\Service\File\Pdf\PdfStructureInspector}
 * across every Catalog shape it distinguishes: direct and indirect
 * `/StructTreeRoot` and `/MarkInfo`, unresolvable references, non-dictionary
 * targets and the `/Marked` flag values.
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

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfStructureInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins taggedness and StructElem counts for hand-built PDFs.
 */
class PdfStructureInspectorGoldenTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var PdfStructureInspector
	 */
	private PdfStructureInspector $inspector;

	/**
	 * Skip when ddn/sapp is absent, else build the inspector.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (class_exists(PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}

		$this->inspector = new PdfStructureInspector();
	}//end setUp()

	/**
	 * Assemble a classic-xref PDF from object bodies keyed by object id.
	 *
	 * @param array<int,string> $objects Object bodies; object 1 is the root.
	 *
	 * @return string The PDF bytes.
	 */
	private static function buildPdf(array $objects): string {
		ksort($objects);
		$pdf     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf          .= $oid." 0 obj\n".$body."\nendobj\n";
		}

		$maxOid    = max(array_keys($objects));
		$xrefStart = strlen($pdf);
		$pdf      .= "xref\n0 ".($maxOid + 1)."\n0000000000 65535 f \n";
		for ($oid = 1; $oid <= $maxOid; $oid++) {
			$pdf .= sprintf("%010d 00000 n \n", ($offsets[$oid] ?? 0));
		}

		$pdf .= "trailer\n<<\n  /Size ".($maxOid + 1)."\n  /Root 1 0 R\n>>\nstartxref\n".$xrefStart."\n%%EOF\n";
		return $pdf;
	}//end buildPdf()

	/**
	 * Catalog shapes and the verdict the inspector gives today.
	 *
	 * @return array<string,array{0:array<int,string>,1:array{tagged:bool,structElementCount:int}}>
	 */
	public static function shapes(): array {
		$pages = "<< /Type /Pages /Kids [ ] /Count 0 >>";
		return [
			'direct StructTreeRoot'                  => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /StructTreeRoot << /Type /StructTreeRoot >> >>", 2 => $pages],
				['tagged' => true, 'structElementCount' => 0],
			],
			'indirect StructTreeRoot'                => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R >>", 2 => $pages, 3 => "<< /Type /StructTreeRoot >>"],
				['tagged' => true, 'structElementCount' => 0],
			],
			'StructTreeRoot to non-dict, no MarkInfo' => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R >>", 2 => $pages, 3 => "42"],
				['tagged' => false, 'structElementCount' => 0],
			],
			'StructTreeRoot to missing object'       => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 9 0 R >>", 2 => $pages],
				['tagged' => false, 'structElementCount' => 0],
			],
			'StructTreeRoot non-dict, Marked true'   => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R /MarkInfo << /Marked true >> >>", 2 => $pages, 3 => "42"],
				['tagged' => true, 'structElementCount' => 0],
			],
			'direct MarkInfo Marked true'            => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /MarkInfo << /Marked true >> >>", 2 => $pages],
				['tagged' => true, 'structElementCount' => 0],
			],
			'direct MarkInfo Marked false'           => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /MarkInfo << /Marked false >> >>", 2 => $pages],
				['tagged' => false, 'structElementCount' => 0],
			],
			'MarkInfo without Marked'                => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /MarkInfo << /Suspects false >> >>", 2 => $pages],
				['tagged' => false, 'structElementCount' => 0],
			],
			'indirect MarkInfo Marked true'          => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /MarkInfo 3 0 R >>", 2 => $pages, 3 => "<< /Marked true >>"],
				['tagged' => true, 'structElementCount' => 0],
			],
			'MarkInfo to non-dict'                   => [
				[1 => "<< /Type /Catalog /Pages 2 0 R /MarkInfo 3 0 R >>", 2 => $pages, 3 => "(text)"],
				['tagged' => false, 'structElementCount' => 0],
			],
			'StructElems among other objects'        => [
				[
					1 => "<< /Type /Catalog /Pages 2 0 R >>",
					2 => $pages,
					3 => "<< /Type /StructElem /S /P >>",
					4 => "<< /S /P >>",
					5 => "7",
					6 => "<< /Type /StructElem /S /H1 >>",
					7 => "<< /Type /Font /Subtype /Type1 >>",
					8 => "[ 1 2 3 ]",
				],
				['tagged' => false, 'structElementCount' => 2],
			],
			'no Catalog object'                      => [
				[1 => "<< /Type /Pages /Kids [ ] /Count 0 >>", 2 => "<< /Type /StructElem >>"],
				['tagged' => false, 'structElementCount' => 1],
			],
		];
	}//end shapes()

	/**
	 * Each Catalog shape yields the pinned verdict.
	 *
	 * @param array<int,string>                              $objects  Object bodies.
	 * @param array{tagged:bool,structElementCount:int}      $expected Pinned verdict.
	 *
	 * @return void
	 */
	#[DataProvider('shapes')]
	public function testShapeVerdict(array $objects, array $expected): void {
		$doc = PDFDoc::from_string(buffer: self::buildPdf(objects: $objects));
		self::assertNotFalse($doc);

		self::assertSame($expected, $this->inspector->inspect(doc: $doc));
		self::assertSame($expected['tagged'], $this->inspector->isTagged(doc: $doc));
		self::assertSame($expected['structElementCount'], $this->inspector->countStructElements(doc: $doc));
	}//end testShapeVerdict()
}//end class
