<?php

declare(strict_types=1);

/**
 * OdtWriter tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export\Odt
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Export\Odt;

use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Service\Export\Odt\OdtWriter;
use OCA\OpenRegister\Service\Export\RowsPdfSection;
use OCA\OpenRegister\Service\Reporting\HtmlReportWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for {@see OdtWriter}: export sections and report HTML become an ODT
 * an office server can convert to PDF.
 *
 * @uses \OCA\OpenRegister\Service\Export\Odt\HtmlToOdt
 * @uses \OCA\OpenRegister\Service\Export\Odt\OdtTableBuilder
 * @uses \OCA\OpenRegister\Service\Export\Odt\VisibleText
 */
class OdtWriterTest extends TestCase {

	/**
	 * The parts of the last package, keyed by name.
	 *
	 * @var array<string, string>
	 */
	private array $parts = [];

	/**
	 * The package's entry names in order.
	 *
	 * @var list<string>
	 */
	private array $names = [];

	/**
	 * Open an ODT and keep its parts.
	 *
	 * @param string $bytes The package bytes.
	 *
	 * @return void
	 */
	private function open(string $bytes): void {
		$file = tempnam(sys_get_temp_dir(), 'odt');
		file_put_contents($file, $bytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($file));
		$this->parts = [];
		$this->names = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string) $zip->getNameIndex($index);
			$this->names[] = $name;
			$this->parts[$name] = (string) $zip->getFromIndex($index);
			if ($name === 'mimetype') {
				$this->assertSame(ZipArchive::CM_STORE, $zip->statIndex($index)['comp_method']);
			}
		}

		$zip->close();
		unlink($file);
	}//end open()

	/**
	 * XPath over one part, with the ODF namespaces registered.
	 *
	 * @param string $part The part name.
	 *
	 * @return DOMXPath
	 */
	private function xpath(string $part): DOMXPath {
		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($this->parts[$part]), $part . ' is well-formed');
		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
		$xpath->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
		$xpath->registerNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
		$xpath->registerNamespace('style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0');
		$xpath->registerNamespace('fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0');
		$xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');

		return $xpath;
	}//end xpath()

	/**
	 * Two export sections become one landscape A4 document with a table per section.
	 *
	 * @return void
	 */
	public function testExportSectionsBecomeTables(): void {
		$section = new RowsPdfSection();
		$html = $section->build(title: 'Personen', columns: ['naam' => 'Naam', 'plaats' => 'Plaats'], rows: [
			['naam' => 'Łukasz Żółć', 'plaats' => 'Gdańsk'],
			['naam' => 'Αλέξανδρος', 'plaats' => 'Αθήνα'],
			['naam' => '<b>Jan</b> & co', 'plaats' => '東京'],
		]) . $section->build(title: 'Leeg', columns: ['a' => 'A'], rows: []);

		$this->open(bytes: (new OdtWriter())->fromHtml(html: $html, landscape: true));

		$this->assertSame('mimetype', $this->names[0]);
		$this->assertSame('application/vnd.oasis.opendocument.text', $this->parts['mimetype']);
		$this->assertArrayHasKey('META-INF/manifest.xml', $this->parts);

		$content = $this->xpath(part: 'content.xml');
		$this->assertSame(['Personen', 'Leeg'], array_map(static fn ($node) => $node->textContent, iterator_to_array($content->query('//text:h'))));
		$this->assertSame(2, $content->query('//table:table')->length);
		$this->assertSame('Naam', $content->evaluate('string(//table:table[1]/table:table-header-rows/table:table-row/table:table-cell[1])'));
		$this->assertSame(3, $content->query('//table:table[1]/table:table-row')->length);
		$this->assertSame('Łukasz Żółć', $content->evaluate('string(//table:table[1]/table:table-row[1]/table:table-cell[1])'));
		$this->assertSame('東京', $content->evaluate('string(//table:table[1]/table:table-row[3]/table:table-cell[2])'));
		$this->assertSame('<b>Jan</b> & co', $content->evaluate('string(//table:table[1]/table:table-row[3]/table:table-cell[1])'));
		$this->assertStringContainsString('Rows: 3', $content->evaluate('string(//text:p[1])'));

		$this->assertSame(1, $content->query('//text:h[2][@text:style-name="OR_H1_Break"]')->length, 'The second section starts on a new page.');
		$this->assertSame(1, $content->query('//style:style[@style:name="OR_H1_Break"]/style:paragraph-properties[@fo:break-before="page"]')->length);

		$styles = $this->xpath(part: 'styles.xml');
		$this->assertSame('29.7cm', $styles->evaluate('string(//style:page-layout-properties/@fo:page-width)'));
		$this->assertSame('21cm', $styles->evaluate('string(//style:page-layout-properties/@fo:page-height)'));
		$this->assertSame('landscape', $styles->evaluate('string(//style:page-layout-properties/@style:print-orientation)'));
		$this->assertSame(1, $styles->query('//style:footer//text:page-number')->length);
		$this->assertSame(1, $styles->query('//style:footer//text:page-count')->length);

		$this->assertSame('Personen', $this->xpath(part: 'meta.xml')->evaluate('string(//dc:title)'));
	}//end testExportSectionsBecomeTables()

	/**
	 * A dashboard report becomes a portrait A4 document with its widgets in order.
	 *
	 * @return void
	 */
	public function testReportBecomesHeadingsTablesAndHeadlines(): void {
		$html = (new HtmlReportWriter())->write(
			dashboard: ['titel' => 'Kwartaal', 'beschrijving' => 'Overzicht Q3'],
			resolvedWidgets: [
				['widget' => ['title' => 'Open zaken', 'type' => 'kpi'], 'data' => ['value' => 42]],
				['widget' => ['title' => 'Per status', 'type' => 'table'], 'data' => ['groups' => [['key' => 'open', 'count' => 3], ['key' => 'dicht', 'count' => 9]]]],
				['widget' => ['title' => 'Statistiek', 'subtitle' => 'Bedragen', 'type' => 'stats'], 'data' => ['sum' => 100, 'avg' => 12.5]],
				['widget' => ['title' => 'Kapot', 'type' => 'kpi'], 'data' => null],
			]
		);

		$this->open(bytes: (new OdtWriter())->fromHtml(html: $html, landscape: false));

		$content = $this->xpath(part: 'content.xml');
		$this->assertSame(['Kwartaal', 'Open zaken', 'Per status', 'Statistiek', 'Kapot'], array_map(static fn ($node) => $node->textContent, iterator_to_array($content->query('//text:h'))));
		$this->assertSame('42', $content->evaluate('string(//text:p[@text:style-name="OR_Headline"])'));
		$this->assertSame('Key', $content->evaluate('string(//table:table[1]/table:table-header-rows//table:table-cell[1])'));
		$this->assertSame('dicht', $content->evaluate('string(//table:table[1]/table:table-row[2]/table:table-cell[1])'));
		$this->assertSame('sum', $content->evaluate('string(//table:table[2]/table:table-row[1]/table:table-cell[1])'));
		$this->assertSame('12.5', $content->evaluate('string(//table:table[2]/table:table-row[2]/table:table-cell[2])'));
		$this->assertSame('Bedragen', $content->evaluate('string(//text:p[@text:style-name="OR_Subtle"][2])'));
		$this->assertStringContainsString('No data available', $content->evaluate('string(//text:p[@text:style-name="OR_Error"])'));
		$this->assertStringNotContainsString('grid-template-columns', $this->parts['content.xml'], 'The report CSS is not copied as text.');
		$this->assertSame(1, substr_count($this->parts['content.xml'], 'xmlns:text='), 'Namespaces are declared once.');

		$styles = $this->xpath(part: 'styles.xml');
		$this->assertSame('21cm', $styles->evaluate('string(//style:page-layout-properties/@fo:page-width)'));
		$this->assertSame('portrait', $styles->evaluate('string(//style:page-layout-properties/@style:print-orientation)'));
		$this->assertSame('Kwartaal', $this->xpath(part: 'meta.xml')->evaluate('string(//dc:title)'));
	}//end testReportBecomesHeadingsTablesAndHeadlines()

	/**
	 * Scripts, styles and links in the HTML never reach the document.
	 *
	 * @return void
	 */
	public function testActiveContentIsDropped(): void {
		$html = '<html><head><title>T</title><link rel="stylesheet" href="file:///etc/passwd"><style>p{}</style></head>'
			. '<body><script>alert(1)</script><h1>Kop</h1><p>Tekst <img src="file:///etc/passwd"> hier</p></body></html>';

		$this->open(bytes: (new OdtWriter())->fromHtml(html: $html, landscape: false));

		$this->assertStringNotContainsString('alert', $this->parts['content.xml']);
		$this->assertStringNotContainsString('passwd', $this->parts['content.xml']);
		$this->assertSame(['META-INF/manifest.xml', 'content.xml', 'meta.xml', 'mimetype', 'styles.xml'], $this->sortedNames());
		$this->assertSame('Tekst hier', preg_replace('/\s+/', ' ', $this->xpath(part: 'content.xml')->evaluate('string(//text:p)')));
	}//end testActiveContentIsDropped()

	/**
	 * Without a heading the document is titled "Document".
	 *
	 * @return void
	 */
	public function testUntitledDocument(): void {
		$this->open(bytes: (new OdtWriter())->fromHtml(html: '<p>Alleen tekst</p>', landscape: false));

		$this->assertSame('Document', $this->xpath(part: 'meta.xml')->evaluate('string(//dc:title)'));
	}//end testUntitledDocument()

	/**
	 * Entry names, sorted.
	 *
	 * @return list<string>
	 */
	private function sortedNames(): array {
		$names = $this->names;
		sort($names);

		return $names;
	}//end sortedNames()
}//end class
