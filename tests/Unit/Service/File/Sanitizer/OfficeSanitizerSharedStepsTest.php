<?php

/**
 * OfficeSanitizerSharedStepsTest
 *
 * Characterisation tests for the package steps the DOCX and ODT sanitisers
 * share: copying to a separate destination, the copy failure, the
 * malformed-part failure, and sentinel scrubbing of the metadata parts not
 * covered by the per-format tests (app.xml, custom.xml, empty fields and
 * non-string user-defined fields).
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

namespace Unit\Service\File\Sanitizer;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\SanitizerInterface;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pins the behaviour shared by {@see DocxSanitizer} and {@see OdtSanitizer}.
 */
class OfficeSanitizerSharedStepsTest extends TestCase {

	/**
	 * Temp files to clean up.
	 *
	 * @var string[]
	 */
	private array $tempFiles = [];

	/**
	 * Clean up temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Sanitising to a separate destination leaves the source untouched.
	 *
	 * @return void
	 */
	public function testOdtCopiesToSeparateDestination(): void {
		$source = $this->package('odt', ['content.xml' => $this->odtContent(), 'meta.xml' => $this->odtMeta()]);
		$dest = $this->tempPath('odt');
		$before = file_get_contents($source);

		$report = (new OdtSanitizer('S3NT'))->sanitize($source, $dest);

		$this->assertSame($before, file_get_contents($source));
		$this->assertSame(1, $report->hyperlinksFlattened);
		$this->assertSame(1, $report->metadataFieldsScrubbed);
		$content = $this->part($dest, 'content.xml');
		$this->assertStringNotContainsString('text:a', $content);
		$this->assertStringContainsString('LINK-TEXT', $content);
		$meta = $this->part($dest, 'meta.xml');
		$this->assertStringContainsString('<dc:creator>S3NT</dc:creator>', $meta);
		$this->assertStringContainsString('<dc:title>  </dc:title>', $meta);
		$this->assertStringContainsString('42.5', $meta);
		$this->assertStringContainsString('meta:value-type="string">  </meta:user-defined>', $meta);
	}//end testOdtCopiesToSeparateDestination()

	/**
	 * Sanitising to a separate destination leaves the source untouched (DOCX),
	 * and app.xml / custom.xml string fields are scrubbed when non-empty.
	 *
	 * @return void
	 */
	public function testDocxCopiesAndScrubsAppAndCustomParts(): void {
		$source = $this->package(
			'docx',
			[
				'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
				'docProps/app.xml' => $this->docxApp(),
				'docProps/custom.xml' => $this->docxCustom(),
				'word/document.xml' => $this->docxDocument(),
			]
		);
		$dest = $this->tempPath('docx');
		$before = file_get_contents($source);

		$report = (new DocxSanitizer('S3NT'))->sanitize($source, $dest);

		$this->assertSame($before, file_get_contents($source));
		// Company + 2 non-empty custom strings; Manager is empty.
		$this->assertSame(3, $report->metadataFieldsScrubbed);
		$this->assertSame(1, $report->trackedChangesAccepted);
		$app = $this->part($dest, 'docProps/app.xml');
		$this->assertStringContainsString('<Company>S3NT</Company>', $app);
		$this->assertStringContainsString('<Manager> </Manager>', $app);
		$custom = $this->part($dest, 'docProps/custom.xml');
		$this->assertSame(2, substr_count($custom, '>S3NT<'));
		$this->assertStringContainsString('<vt:i4>7</vt:i4>', $custom);
		$this->assertStringContainsString('INSERTED', $this->part($dest, 'word/document.xml'));
		$this->assertStringNotContainsString('w:ins', $this->part($dest, 'word/document.xml'));
	}//end testDocxCopiesAndScrubsAppAndCustomParts()

	/**
	 * A malformed ODT part raises a corrupt-zip exception with the ODT message.
	 *
	 * @return void
	 */
	public function testOdtMalformedPartRaises(): void {
		$path = $this->package('odt', ['content.xml' => '<office:document-content><broken']);

		$this->assertFailure(
			sanitizer: new OdtSanitizer('S3NT'),
			path: $path,
			expected: [SanitizationException::REASON_CORRUPT_ZIP, 'An ODT part was not well-formed XML']
		);
	}//end testOdtMalformedPartRaises()

	/**
	 * A malformed DOCX part raises a corrupt-zip exception with the DOCX message.
	 *
	 * @return void
	 */
	public function testDocxMalformedPartRaises(): void {
		$path = $this->package(
			'docx',
			[
				'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
				'docProps/core.xml' => '<cp:coreProperties><broken',
			]
		);

		$this->assertFailure(
			sanitizer: new DocxSanitizer('S3NT'),
			path: $path,
			expected: [SanitizationException::REASON_CORRUPT_ZIP, 'A document part was not well-formed XML']
		);
	}//end testDocxMalformedPartRaises()

	/**
	 * A failed copy raises an internal exception for both formats.
	 *
	 * @return void
	 */
	public function testCopyFailureRaises(): void {
		$missing = sys_get_temp_dir() . '/no-such-dir-' . uniqid() . '/missing.bin';
		foreach ([new OdtSanitizer('S3NT'), new DocxSanitizer('S3NT')] as $sanitizer) {
			set_error_handler(static fn (): bool => true);
			try {
				$sanitizer->sanitize($missing, $missing . '.out');
				$this->fail('Expected a SanitizationException');
			} catch (SanitizationException $e) {
				$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
				$this->assertSame('Could not copy source document to destination', $e->getMessage());
			} finally {
				restore_error_handler();
			}
		}
	}//end testCopyFailureRaises()

	/**
	 * Assert that sanitising in place raises the expected reason and message.
	 *
	 * @param SanitizerInterface $sanitizer The sanitiser.
	 * @param string $path The package path.
	 * @param array{0:string,1:string} $expected Reason and message.
	 *
	 * @return void
	 */
	private function assertFailure(SanitizerInterface $sanitizer, string $path, array $expected): void {
		// The libxml parse warnings are expected; only the exception is pinned.
		set_error_handler(static fn (): bool => true);
		try {
			$sanitizer->sanitize($path, $path);
			$this->fail('Expected a SanitizationException');
		} catch (SanitizationException $e) {
			$this->assertSame($expected[0], $e->getReason());
			$this->assertSame($expected[1], $e->getMessage());
		} finally {
			restore_error_handler();
		}
	}//end assertFailure()

	/**
	 * Create a temp path with the given extension.
	 *
	 * @param string $extension The file extension.
	 *
	 * @return string The path.
	 */
	private function tempPath(string $extension): string {
		$path = tempnam(sys_get_temp_dir(), 'shared_') . '.' . $extension;
		$this->tempFiles[] = $path;
		return $path;
	}//end tempPath()

	/**
	 * Build a ZIP package from part name => content.
	 *
	 * @param string $extension The file extension.
	 * @param array<string, string> $parts The parts.
	 *
	 * @return string The package path.
	 */
	private function package(string $extension, array $parts): string {
		$path = $this->tempPath($extension);
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		return $path;
	}//end package()

	/**
	 * Read one part of a package.
	 *
	 * @param string $path The package path.
	 * @param string $name The part name.
	 *
	 * @return string The part content.
	 */
	private function part(string $path, string $name): string {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path) === true);
		$content = $zip->getFromName($name);
		$zip->close();
		$this->assertIsString($content);
		return $content;
	}//end part()

	/**
	 * ODT content.xml with one hyperlink.
	 *
	 * @return string The XML.
	 */
	private function odtContent(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xlink="http://www.w3.org/1999/xlink">'
			. '<office:body><office:text><text:p><text:a xlink:href="https://example.com">LINK-TEXT</text:a></text:p>'
			. '</office:text></office:body></office:document-content>';
	}//end odtContent()

	/**
	 * ODT meta.xml with an empty title, a non-empty creator, an empty string
	 * user-defined field and a float user-defined field.
	 *
	 * @return string The XML.
	 */
	private function odtMeta(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<office:meta><dc:creator>Robert</dc:creator><dc:title>  </dc:title>'
			. '<meta:user-defined meta:name="Empty" meta:value-type="string">  </meta:user-defined>'
			. '<meta:user-defined meta:name="Score" meta:value-type="float">42.5</meta:user-defined>'
			. '</office:meta></office:document-meta>';
	}//end odtMeta()

	/**
	 * DOCX app.xml with a Company and an empty Manager.
	 *
	 * @return string The XML.
	 */
	private function docxApp(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
			. '<Company>Acme</Company><Manager> </Manager></Properties>';
	}//end docxApp()

	/**
	 * DOCX custom.xml with two string properties and one integer property.
	 *
	 * @return string The XML.
	 */
	private function docxCustom(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" '
			. 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
			. '<property name="A"><vt:lpwstr>Marie</vt:lpwstr></property>'
			. '<property name="B"><vt:lpstr>Pierre</vt:lpstr></property>'
			. '<property name="C"><vt:i4>7</vt:i4></property></Properties>';
	}//end docxCustom()

	/**
	 * DOCX document.xml with one tracked insertion.
	 *
	 * @return string The XML.
	 */
	private function docxDocument(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
			. '<w:p><w:ins w:id="1"><w:r><w:t>INSERTED</w:t></w:r></w:ins></w:p></w:body></w:document>';
	}//end docxDocument()
}//end class
