<?php

declare(strict_types=1);

/**
 * FpdfTableRenderer tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Export\Pdf;

use OCA\OpenRegister\Service\Export\Pdf\FpdfTableRenderer;
use OCA\OpenRegister\Service\Export\RowsPdfSection;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * Tests for {@see FpdfTableRenderer}.
 */
class FpdfTableRendererTest extends TestCase {

	/**
	 * Every heading and cell of every section is in the text layer, one section per page.
	 *
	 * @return void
	 */
	public function testRendersEverySectionOnItsOwnPage(): void {
		$section = new RowsPdfSection();
		$bytes = (new FpdfTableRenderer())->render(
			sections: [
				$section->build(title: 'Eerste', columns: ['a' => 'Kolom A'], rows: [['a' => 'Café € 10']]),
				$section->build(title: 'Tweede', columns: ['b' => 'Kolom B'], rows: [['b' => 'waarde']]),
			]
		);

		$document = (new Parser())->parseContent($bytes);
		$text = $document->getText();
		$this->assertCount(2, $document->getPages());
		foreach (['Eerste', 'Kolom A', 'Café € 10', 'Tweede', 'Kolom B', 'waarde', 'Page 1 / 2'] as $expected) {
			$this->assertStringContainsString($expected, $text);
		}
	}//end testRendersEverySectionOnItsOwnPage()

	/**
	 * A table longer than a page continues on the next pages.
	 *
	 * @return void
	 */
	public function testLongTableBreaksAcrossPages(): void {
		$rows = [];
		for ($i = 1; $i <= 200; $i++) {
			$rows[] = ['n' => 'rij ' . $i];
		}

		$bytes = (new FpdfTableRenderer())->render(sections: [(new RowsPdfSection())->build(title: 'Lang', columns: ['n' => 'N'], rows: $rows)]);

		$document = (new Parser())->parseContent($bytes);
		$this->assertGreaterThan(1, count($document->getPages()));
		$this->assertStringContainsString('rij 200', $document->getText());
	}//end testLongTableBreaksAcrossPages()
}//end class
