<?php

/**
 * OpenRegister OdtWriter
 *
 * Writes an OpenDocument Text package from export or report HTML.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Odt
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export\Odt;

use DOMDocument;
use RuntimeException;
use ZipArchive;

/**
 * Writes an A4 ODT, landscape or portrait, with 1.2 cm margins, a "Page n / N"
 * footer and the first heading as document title. An office server converts
 * the result to PDF.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
class OdtWriter {

	/**
	 * Namespace declarations shared by every part.
	 */
	private const NAMESPACES = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
		. 'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
		. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
		. 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
		. 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
		. 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
		. 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" '
		. 'xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" '
		. 'office:version="1.3"';

	/**
	 * Paragraph styles: name => [paragraph properties, text properties].
	 */
	private const PARAGRAPH_STYLES = [
		'OR_H1'       => ['fo:margin-bottom="0.15cm"', 'fo:font-size="14pt" fo:font-weight="bold"'],
		'OR_H1_Break' => ['fo:margin-bottom="0.15cm" fo:break-before="page"', 'fo:font-size="14pt" fo:font-weight="bold"'],
		'OR_H2'       => [
			'fo:margin-top="0.4cm" fo:margin-bottom="0.1cm" fo:keep-with-next="always"',
			'fo:font-size="10pt" fo:font-weight="bold" fo:color="#555555"',
		],
		'OR_Body'     => ['', 'fo:font-size="9pt"'],
		'OR_Meta'     => ['fo:margin-bottom="0.3cm"', 'fo:font-size="8pt" fo:color="#555555"'],
		'OR_Subtle'   => ['', 'fo:font-size="9pt" fo:color="#666666"'],
		'OR_Error'    => ['', 'fo:font-size="9pt" fo:color="#b30000"'],
		'OR_Headline' => ['', 'fo:font-size="24pt" fo:font-weight="bold"'],
		'OR_Cell'     => ['', 'fo:font-size="8pt"'],
		'OR_HeadCell' => ['', 'fo:font-size="8pt" fo:font-weight="bold" fo:color="#ffffff"'],
	];

	/**
	 * Table cell styles: name => background colour, or empty for none.
	 */
	private const CELL_STYLES = [
		'OR_TH'     => '#2c3e50',
		'OR_TD'     => '',
		'OR_TD_Alt' => '#f2f2f2',
	];

	/**
	 * The font every style uses; the office server falls back per script where it lacks glyphs.
	 */
	private const FONT_FACES = '<office:font-face-decls><style:font-face style:name="Liberation Sans"'
		. ' svg:font-family="&apos;Liberation Sans&apos;" style:font-family-generic="swiss"/></office:font-face-decls>';

	/**
	 * The table style.
	 */
	private const TABLE_STYLE = '<style:style style:name="OR_Table" style:family="table">'
		. '<style:table-properties style:rel-width="100%" table:align="margins" fo:margin-bottom="0.3cm"/></style:style>';

	/**
	 * Write the ODT for an HTML document.
	 *
	 * @param string $html      Export section or report HTML.
	 * @param bool   $landscape Landscape A4 when true, portrait A4 otherwise.
	 *
	 * @return string The ODT bytes.
	 *
	 * @throws RuntimeException When the package cannot be written.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function fromHtml(string $html, bool $landscape): string {
		$converter = new HtmlToOdt();
		$text = $converter->convert(html: $html);
		$title = $converter->title();
		if ($title === '') {
			$title = 'Document';
		}

		// The elements were built detached, so each carries its own namespace
		// declarations; reloading with NSCLEAN keeps only the root's.
		$content = new DOMDocument();
		$content->loadXML(
			'<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NAMESPACES . '>'
			. self::FONT_FACES . $this->contentStyles() . '<office:body>' . $text->ownerDocument->saveXML($text) . '</office:body></office:document-content>',
			(LIBXML_NSCLEAN | LIBXML_NONET | LIBXML_PARSEHUGE)
		);

		$parts = [
			'content.xml' => (string) $content->saveXML(),
			'styles.xml' => $this->styles(landscape: $landscape),
			'meta.xml' => '<?xml version="1.0" encoding="UTF-8"?><office:document-meta ' . self::NAMESPACES . '><office:meta>'
				. '<dc:title>' . htmlspecialchars($title, (ENT_XML1 | ENT_QUOTES), 'UTF-8') . '</dc:title>'
				. '<meta:generator>OpenRegister</meta:generator></office:meta></office:document-meta>',
			'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
				. '<manifest:file-entry manifest:full-path="/" manifest:version="1.3"'
				. ' manifest:media-type="application/vnd.oasis.opendocument.text"/>'
				. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
				. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
				. '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
				. '</manifest:manifest>',
		];

		return $this->package(parts: $parts);
	}//end fromHtml()

	/**
	 * The automatic styles of the content part, used by {@see HtmlToOdt} and {@see OdtTableBuilder}.
	 *
	 * @return string
	 */
	private function contentStyles(): string {
		$xml = '<office:automatic-styles>' . self::TABLE_STYLE;
		foreach (self::PARAGRAPH_STYLES as $name => [$paragraph, $text]) {
			$xml .= '<style:style style:name="' . $name . '" style:family="paragraph">'
				. '<style:paragraph-properties ' . $paragraph . '/><style:text-properties style:font-name="Liberation Sans" ' . $text . '/></style:style>';
		}

		foreach (self::CELL_STYLES as $name => $background) {
			$fill = '';
			if ($background !== '') {
				$fill = ' fo:background-color="' . $background . '"';
			}

			$xml .= '<style:style style:name="' . $name . '" style:family="table-cell">'
				. '<style:table-cell-properties' . $fill . ' fo:border="0.25pt solid #cccccc" fo:padding="0.1cm"/></style:style>';
		}

		return $xml . '</office:automatic-styles>';
	}//end contentStyles()

	/**
	 * The styles part: default font, A4 page layout and the page-number footer.
	 *
	 * @param bool $landscape Landscape or portrait.
	 *
	 * @return string
	 */
	private function styles(bool $landscape): string {
		$size = 'fo:page-width="21cm" fo:page-height="29.7cm" style:print-orientation="portrait"';
		if ($landscape === true) {
			$size = 'fo:page-width="29.7cm" fo:page-height="21cm" style:print-orientation="landscape"';
		}

		return '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' . self::NAMESPACES . '>'
			. self::FONT_FACES . '<office:styles>'
			. '<style:default-style style:family="paragraph">'
			. '<style:text-properties style:font-name="Liberation Sans" fo:font-size="9pt" fo:color="#1a1a1a"/></style:default-style>'
			. '<style:style style:name="Standard" style:family="paragraph"/>'
			. '<style:style style:name="OR_Footer" style:family="paragraph"><style:paragraph-properties fo:text-align="end"/>'
			. '<style:text-properties fo:font-size="8pt" fo:color="#4d4d4d"/></style:style>'
			. '</office:styles>'
			. '<office:automatic-styles><style:page-layout style:name="OR_Page"><style:page-layout-properties ' . $size
			. ' fo:margin-top="1.2cm" fo:margin-bottom="0.8cm" fo:margin-left="1.2cm" fo:margin-right="1.2cm"/>'
			. '<style:footer-style><style:header-footer-properties fo:min-height="0.4cm" fo:margin-top="0.2cm"/></style:footer-style>'
			. '</style:page-layout></office:automatic-styles>'
			. '<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="OR_Page">'
			. '<style:footer><text:p text:style-name="OR_Footer">'
			. 'Page <text:page-number text:select-page="current"/> / <text:page-count/></text:p></style:footer>'
			. '</style:master-page></office:master-styles></office:document-styles>';
	}//end styles()

	/**
	 * Zip the parts, with the uncompressed `mimetype` entry first.
	 *
	 * @param array<string, string> $parts Part name => XML.
	 *
	 * @return string The package bytes.
	 *
	 * @throws RuntimeException When the package cannot be written.
	 */
	private function package(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'openregister_odt_');
		$zip = new ZipArchive();
		if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Could not create the ODT package');
		}

		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
		$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
		foreach ($parts as $name => $xml) {
			$zip->addFromString($name, $xml);
		}

		$zip->close();
		$bytes = (string) file_get_contents($path);
		unlink($path);

		return $bytes;
	}//end package()
}//end class
