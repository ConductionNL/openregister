<?php

/**
 * Golden test pinning the orchestration and field-scan outcomes of the
 * DOCX and ODT sanitizers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Sanitizer
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Sanitizer;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pins copy/open/encryption refusals, the copy-to-destination path, the
 * header-part rels reconciliation and the complex-field state machine
 * (nested, unbalanced, non-matching and multi-run instructions).
 *
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer
 */
final class OfficeSanitizerGoldenTest extends TestCase {

	private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const HL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

	/**
	 * Temp paths removed in tearDown.
	 *
	 * @var string[]
	 */
	private array $temp = [];

	/**
	 * Remove temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temp as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A fresh temp path.
	 *
	 * @param string $ext Extension
	 *
	 * @return string
	 */
	private function tempPath(string $ext): string {
		$path = sys_get_temp_dir() . '/golden_' . bin2hex(random_bytes(6)) . $ext;
		$this->temp[] = $path;
		return $path;
	}//end tempPath()

	/**
	 * Write a zip with the given parts.
	 *
	 * @param string                $ext   Extension
	 * @param array<string, string> $parts Part name => contents
	 *
	 * @return string
	 */
	private function zip(string $ext, array $parts): string {
		$path = $this->tempPath($ext);
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($parts as $name => $contents) {
			$zip->addFromString($name, $contents);
		}

		$zip->close();
		return $path;
	}//end zip()

	/**
	 * Read one part.
	 *
	 * @param string $path Zip path
	 * @param string $name Part name
	 *
	 * @return string|false
	 */
	private function part(string $path, string $name): string|false {
		$zip = new ZipArchive();
		$zip->open($path);
		$contents = $zip->getFromName($name);
		$zip->close();
		return $contents;
	}//end part()

	/**
	 * A run carrying a field char.
	 *
	 * @param string $type begin / separate / end
	 *
	 * @return string
	 */
	private static function fld(string $type): string {
		return '<w:r><w:fldChar w:fldCharType="' . $type . '"/></w:r>';
	}//end fld()

	/**
	 * A run carrying instruction text.
	 *
	 * @param string $instr Instruction
	 *
	 * @return string
	 */
	private static function instr(string $instr): string {
		return '<w:r><w:instrText xml:space="preserve">' . $instr . '</w:instrText></w:r>';
	}//end instr()

	/**
	 * A plain text run.
	 *
	 * @param string $text Text
	 *
	 * @return string
	 */
	private static function text(string $text): string {
		return '<w:r><w:t>' . $text . '</w:t></w:r>';
	}//end text()

	/**
	 * Complex fields: multi-run, nested, non-matching and unbalanced.
	 *
	 * @return void
	 */
	public function testDocxComplexFieldsAndHeaderRels(): void {
		$body = '<w:p>' . self::text('KEEP-A') . self::fld('begin') . self::instr(' user') . self::instr('name \\* MERGEFORMAT')
			. self::fld('separate') . self::text('CACHED-ONE') . self::fld('end') . self::text('KEEP-B') . '</w:p>'
			. '<w:p>' . self::fld('begin') . self::instr('AUTHOR') . self::fld('begin') . self::instr('DATE') . self::fld('end')
			. self::fld('separate') . self::text('CACHED-TWO') . self::fld('end') . self::text('KEEP-C') . '</w:p>'
			. '<w:p>' . self::fld('begin') . self::instr('PAGE') . self::fld('separate') . self::text('KEEP-PAGE') . self::fld('end') . '</w:p>'
			. '<w:p>' . self::text('KEEP-D') . self::fld('begin') . self::instr('LASTSAVEDBY') . self::text('TAIL') . '</w:p>'
			. '<w:p>' . self::fld('begin') . self::instr('TITLE') . self::text('KEEP-E') . '</w:p>';
		$document = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body>'
			. $body . '</w:body></w:document>';
		$header = '<?xml version="1.0" encoding="UTF-8"?><w:hdr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:p>'
			. '<w:hyperlink r:id="rIdH1">' . self::text('HDR-LINK') . '</w:hyperlink></w:p></w:hdr>';
		$headerRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rIdH1" Type="' . self::HL . '" Target="https://example.org/x" TargetMode="External"/>'
			. '<Relationship Id="rIdH2" Type="' . self::HL . '" Target="https://example.org/y" TargetMode="External"/></Relationships>';
		$source = $this->zip(
			'.docx',
			[
				'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
				'word/document.xml' => $document,
				'word/header1.xml' => $header,
				'word/_rels/header1.xml.rels' => $headerRels,
			]
		);
		$dest = $this->tempPath('.docx');

		$report = (new DocxSanitizer('SENTINEL'))->sanitize($source, $dest);

		// The unbalanced LASTSAVEDBY begin counts, but only its begin run goes;
		// nested instructions concatenate ("AUTHORDATE") and so do not match.
		$this->assertSame(2, $report->fieldCodesStripped);
		$this->assertSame(1, $report->hyperlinksFlattened);
		$this->assertSame('SENTINEL', $report->sentinelApplied);

		$out = (string)$this->part($dest, 'word/document.xml');
		foreach (['KEEP-A', 'KEEP-B', 'KEEP-C', 'KEEP-PAGE', 'KEEP-D', 'KEEP-E', 'TITLE', 'AUTHOR', 'CACHED-TWO', 'LASTSAVEDBY', 'TAIL'] as $kept) {
			$this->assertStringContainsString($kept, $out, $kept);
		}

		$this->assertSame(9, substr_count($out, 'w:fldCharType'));
		foreach (['user', 'CACHED-ONE'] as $gone) {
			$this->assertStringNotContainsString($gone, $out, $gone);
		}

		$rels = (string)$this->part($dest, 'word/_rels/header1.xml.rels');
		$this->assertStringNotContainsString('rIdH1', $rels);
		$this->assertStringContainsString('rIdH2', $rels);
		$this->assertStringContainsString('HDR-LINK', (string)$this->part($dest, 'word/header1.xml'));

		// The source is left untouched when copying to a different destination.
		$this->assertStringContainsString('CACHED-ONE', (string)$this->part($source, 'word/document.xml'));
	}//end testDocxComplexFieldsAndHeaderRels()

	/**
	 * Refusals: copy failure, unreadable zip, missing package root.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$cases = [];
		$notZip = $this->tempPath('.bin');
		file_put_contents($notZip, 'not a zip');
		$docxNoRoot = $this->zip('.docx', ['EncryptedPackage' => 'x']);
		$odtNoRoot = $this->zip('.odt', ['EncryptedPackage' => 'x']);
		$missing = sys_get_temp_dir() . '/golden_missing_' . bin2hex(random_bytes(6));

		foreach ([new DocxSanitizer('S'), new OdtSanitizer('S')] as $sanitizer) {
			$noRoot = $docxNoRoot;
			if ($sanitizer instanceof OdtSanitizer) {
				$noRoot = $odtNoRoot;
			}

			foreach ([[$missing, $this->tempPath('.out')], [$notZip, $notZip], [$noRoot, $noRoot]] as [$src, $dst]) {
				try {
					@$sanitizer->sanitize($src, $dst);
					$cases[] = 'none';
				} catch (SanitizationException $e) {
					$cases[] = $e->getReason() . '|' . $e->getMessage();
				}
			}
		}

		$this->assertSame(
			[
				SanitizationException::REASON_INTERNAL . '|Could not copy source document to destination',
				$cases[1],
				SanitizationException::REASON_ENCRYPTED . '|Document is encrypted or not a valid OOXML package',
				SanitizationException::REASON_INTERNAL . '|Could not copy source document to destination',
				SanitizationException::REASON_CORRUPT_ZIP . '|Could not open ODT ZIP (code 19)',
				SanitizationException::REASON_ENCRYPTED . '|Document is encrypted or not a valid ODT package',
			],
			$cases
		);
		$this->assertStringStartsWith(SanitizationException::REASON_CORRUPT_ZIP . '|', $cases[1]);
	}//end testRefusals()

	/**
	 * ODT: copy to destination runs the content passes and leaves the source alone.
	 *
	 * @return void
	 */
	public function testOdtCopiesAndSanitizes(): void {
		$content = '<?xml version="1.0" encoding="UTF-8"?><office:document-content'
			. ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:xlink="http://www.w3.org/1999/xlink"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"><office:body><office:text>'
			. '<text:p>KEEP<office:annotation><text:p>NOTE</text:p></office:annotation>'
			. '<text:a xlink:href="https://example.org">LINK</text:a></text:p>'
			. '</office:text></office:body></office:document-content>';
		$source = $this->zip('.odt', ['content.xml' => $content]);
		$dest = $this->tempPath('.odt');

		$report = (new OdtSanitizer('S'))->sanitize($source, $dest);

		$this->assertSame(1, $report->commentsRemoved);
		$this->assertSame(1, $report->hyperlinksFlattened);
		$this->assertSame(0, $report->revisionAttributesStripped);
		$this->assertSame(0, $report->customXmlPartsDropped);
		$out = (string)$this->part($dest, 'content.xml');
		$this->assertStringNotContainsString('NOTE', $out);
		$this->assertStringContainsString('LINK', $out);
		$this->assertStringContainsString('NOTE', (string)$this->part($source, 'content.xml'));
	}//end testOdtCopiesAndSanitizes()
}//end class
