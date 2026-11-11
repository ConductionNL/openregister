<?php

/**
 * Unit test for RowsPdfSection: the HTML table a PDF section is made of, with
 * escaped headers and cells, cell text per value kind and truncated long cells.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Service\Export\RowsPdfSection;
use OCA\OpenRegister\Service\Export\RowsTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Export\RowsPdfSection
 */
class RowsPdfSectionTest extends TestCase {

	/**
	 * The body rows of a section, without the export moment (which is the clock's).
	 *
	 * @param string $html The section.
	 *
	 * @return string
	 */
	private function body(string $html): string {
		$start = strpos($html, '<tbody>');
		$this->assertNotFalse($start);
		return substr($html, $start);
	}//end body()

	/**
	 * A list of columns is its own labels; the title and labels are escaped; the meta line counts the rows.
	 *
	 * @return void
	 */
	public function testAListOfColumnsLabelsItselfAndEverythingIsEscaped(): void {
		$html = (new RowsPdfSection())->build(table: new RowsTable(title: 'Zaken <& co>', columns: ['title', 'a"b'], rows: [['title' => 'x', 'a"b' => '<b>']]));

		$this->assertStringStartsWith('<div class="pdf-section"><h1>Zaken &lt;&amp; co&gt;</h1><p class="meta">Exported: ', $html);
		$this->assertMatchesRegularExpression('/Exported: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} &middot; Rows: 1<\/p>/', $html);
		$this->assertStringContainsString('<thead><tr><th>title</th><th>a&quot;b</th></tr></thead>', $html);
		$this->assertSame('<tbody><tr><td>x</td><td>&lt;b&gt;</td></tr></tbody></table></div>', $this->body(html: $html));
	}//end testAListOfColumnsLabelsItselfAndEverythingIsEscaped()

	/**
	 * Each value kind has its own cell text: empty, true/false, a joined list, JSON for a map, the scalar.
	 *
	 * @return void
	 */
	public function testCellTextPerValueKind(): void {
		$columns = ['n' => 'N', 'b' => 'B', 'l' => 'L', 'm' => 'M', 's' => 'S', 'gone' => 'Gone'];
		$row     = ['n' => null, 'b' => false, 'l' => [1, true, null], 'm' => ['k' => 'é/x'], 's' => 4.5];

		$html = (new RowsPdfSection())->build(table: new RowsTable(title: 't', columns: $columns, rows: [$row, 'not a row']));

		$this->assertStringContainsString('<th>N</th><th>B</th><th>L</th><th>M</th><th>S</th><th>Gone</th>', $html);
		$this->assertSame(
			'<tbody><tr><td></td><td>false</td><td>1, true, </td><td>{&quot;k&quot;:&quot;é/x&quot;}</td><td>4.5</td><td></td></tr>'
			.'<tr><td></td><td></td><td></td><td></td><td></td><td></td></tr></tbody></table></div>',
			$this->body(html: $html)
		);
		$this->assertStringContainsString('Rows: 2</p>', $html);
	}//end testCellTextPerValueKind()

	/**
	 * A cell longer than 200 characters is cut at 200 with an ellipsis; one of exactly 200 is kept.
	 *
	 * @return void
	 */
	public function testLongCellsAreTruncated(): void {
		$long  = str_repeat('é', 201);
		$exact = str_repeat('a', 200);
		$html  = (new RowsPdfSection())->build(table: new RowsTable(title: 't', columns: ['x' => 'X', 'y' => 'Y'], rows: [['x' => $long, 'y' => $exact]]));

		$this->assertSame('<tbody><tr><td>'.str_repeat('é', 200).'…</td><td>'.$exact.'</td></tr></tbody></table></div>', $this->body(html: $html));
	}//end testLongCellsAreTruncated()
}//end class
