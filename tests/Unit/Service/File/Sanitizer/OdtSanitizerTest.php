<?php

/**
 * OdtSanitizerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\Sanitizer\OdtSanitizer}.
 * A synthetic .odt ZIP is built in-test covering each surgical pass.
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
use OCA\OpenRegister\Service\File\Sanitizer\OdtSanitizer;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for {@see OdtSanitizer}.
 */
class OdtSanitizerTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var OdtSanitizer
	 */
	private OdtSanitizer $sanitizer;

	/**
	 * Temp files to clean up.
	 *
	 * @var string[]
	 */
	private array $tempFiles = [];

	/**
	 * Reset before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sanitizer = new OdtSanitizer('DocuDesk Anonymisation');
	}//end setUp()

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
	 * supports() matches only the ODT MIME.
	 *
	 * @return void
	 */
	public function testSupports(): void {
		$this->assertTrue($this->sanitizer->supports('application/vnd.oasis.opendocument.text'));
		$this->assertFalse($this->sanitizer->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
	}//end testSupports()

	/**
	 * Each surgical pass produces the expected counts.
	 *
	 * @return void
	 */
	public function testSanitizeCounts(): void {
		$path = $this->buildFixture();
		$report = $this->sanitizer->sanitize($path, $path);

		$this->assertSame(3, $report->commentsRemoved);
		$this->assertSame(1, $report->trackedChangesAccepted);
		$this->assertSame(1, $report->trackedChangesDropped);
		$this->assertSame(1, $report->hyperlinksFlattened);
		$this->assertSame(1, $report->fieldCodesStripped);
		// dc:creator, initial-creator, dc:title, dc:subject (4) + 1 user-defined string.
		$this->assertSame(5, $report->metadataFieldsScrubbed);
		$this->assertSame('DocuDesk Anonymisation', $report->sentinelApplied);
	}//end testSanitizeCounts()

	/**
	 * Annotations, hyperlinks, placeholders, tracked-changes removed from content.
	 *
	 * @return void
	 */
	public function testContentSurgery(): void {
		$path = $this->buildFixture();
		$this->sanitizer->sanitize($path, $path);

		$zip = new ZipArchive();
		$zip->open($path);
		$content = $zip->getFromName('content.xml');

		$this->assertStringNotContainsString('office:annotation', $content);
		$this->assertStringNotContainsString('text:a ', $content);
		$this->assertStringNotContainsString('text:author-name', $content);
		$this->assertStringNotContainsString('text:tracked-changes', $content);
		// Inserted content kept; link text kept.
		$this->assertStringContainsString('INSERTED-TEXT', $content);
		$this->assertStringContainsString('LINK-TEXT', $content);
		$zip->close();
	}//end testContentSurgery()

	/**
	 * meta.xml scrubbed to sentinel; non-string user-defined preserved.
	 *
	 * @return void
	 */
	public function testMetadataScrubbed(): void {
		$path = $this->buildFixture();
		$this->sanitizer->sanitize($path, $path);

		$zip = new ZipArchive();
		$zip->open($path);
		$meta = $zip->getFromName('meta.xml');

		$this->assertStringNotContainsString('Robert Zondervan', $meta);
		$this->assertStringContainsString('DocuDesk Anonymisation', $meta);
		// Non-string user-defined (a float value) preserved.
		$this->assertStringContainsString('42.5', $meta);
		$zip->close();
	}//end testMetadataScrubbed()

	/**
	 * A package without content.xml is treated as encrypted.
	 *
	 * @return void
	 */
	public function testEncryptedRaises(): void {
		$path = tempnam(sys_get_temp_dir(), 'odtenc_') . '.odt';
		$this->tempFiles[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
		$zip->close();

		$this->expectException(SanitizationException::class);
		try {
			$this->sanitizer->sanitize($path, $path);
		} catch (SanitizationException $e) {
			$this->assertSame(SanitizationException::REASON_ENCRYPTED, $e->getReason());
			throw $e;
		}
	}//end testEncryptedRaises()

	/**
	 * Build a synthetic .odt fixture.
	 *
	 * @return string Path to the .odt fixture.
	 */
	private function buildFixture(): string {
		$path = tempnam(sys_get_temp_dir(), 'odt_') . '.odt';
		$this->tempFiles[] = $path;

		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
		$zip->addFromString('content.xml', $this->content());
		$zip->addFromString('meta.xml', $this->meta());
		$zip->close();
		return $path;
	}//end buildFixture()

	/**
	 * content.xml with annotations, tracked changes, hyperlink, placeholder.
	 *
	 * @return string The XML string.
	 */
	private function content(): string {
		$ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
			. 'xmlns:xlink="http://www.w3.org/1999/xlink"';

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content ' . $ns . '><office:body><office:text>'
			. '<text:tracked-changes>'
			. '<text:changed-region text:id="c1"><text:insertion/></text:changed-region>'
			. '<text:changed-region text:id="c2"><text:deletion><text:p>DELETED</text:p></text:deletion></text:changed-region>'
			. '</text:tracked-changes>'
			. '<text:p><office:annotation><text:p>comment one</text:p></office:annotation>para</text:p>'
			. '<text:p><office:annotation><text:p>comment two</text:p></office:annotation></text:p>'
			. '<text:p><office:annotation><text:p>comment three</text:p></office:annotation></text:p>'
			. '<text:p><text:change-start text:change-id="c1"/>INSERTED-TEXT<text:change-end text:change-id="c1"/></text:p>'
			. '<text:p><text:change text:change-id="c2"/></text:p>'
			. '<text:p><text:a xlink:href="mailto:p.jansen@example.com">LINK-TEXT</text:a></text:p>'
			. '<text:p><text:author-name>Robert Zondervan</text:author-name></text:p>'
			. '</office:text></office:body></office:document-content>';
	}//end content()

	/**
	 * meta.xml with PII fields + user-defined string + non-string property.
	 *
	 * @return string The XML string.
	 */
	private function meta(): string {
		$ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" '
			. 'xmlns:dc="http://purl.org/dc/elements/1.1/"';

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-meta ' . $ns . '><office:meta>'
			. '<dc:creator>Robert Zondervan</dc:creator>'
			. '<meta:initial-creator>Robert Zondervan</meta:initial-creator>'
			. '<dc:title>Geheim Dossier</dc:title>'
			. '<dc:subject>Jan Jansen zaak</dc:subject>'
			. '<meta:user-defined meta:name="Reviewer" meta:value-type="string">Marie Curie</meta:user-defined>'
			. '<meta:user-defined meta:name="Score" meta:value-type="float">42.5</meta:user-defined>'
			. '</office:meta></office:document-meta>';
	}//end meta()
}//end class
