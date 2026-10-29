<?php

declare(strict_types=1);

/**
 * Old-versus-new engine comparison for PDF export and Word text extraction
 *
 * Not part of any default suite. Run it on purpose:
 *
 *   ENGINE_COMPARISON_OUT=/some/dir vendor/bin/phpunit -c phpunit.xml tests/Comparison
 *
 * It renders the same export tables with dompdf and FPDF, and extracts the
 * same DOCX and ODT files with PhpWord and WordTextReader. It writes the
 * PDFs and a results.json to ENGINE_COMPARISON_OUT and asserts only that
 * both engines produce output.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Comparison
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Comparison;

use OCA\OpenRegister\Service\Export\Pdf\DompdfTableRenderer;
use OCA\OpenRegister\Service\Export\Pdf\FpdfTableRenderer;
use OCA\OpenRegister\Service\Export\Pdf\PdfTableRenderer;
use OCA\OpenRegister\Service\Export\RowsPdfSection;
use OCA\OpenRegister\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use OCP\IAppConfig;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser;

/**
 * Renders and extracts the same inputs with the old and the new engine.
 */
class EngineComparisonTest extends TestCase {

	/**
	 * Where the PDFs and results.json go.
	 *
	 * @return string
	 */
	private function outDir(): string {
		$dir = (string) (getenv('ENGINE_COMPARISON_OUT') ?: sys_get_temp_dir() . '/engine-comparison');
		if (is_dir($dir) === false) {
			mkdir($dir, 0775, true);
		}

		return $dir;
	}//end outDir()

	/**
	 * Merge one block of results into results.json.
	 *
	 * @param string $key     Top-level key.
	 * @param array  $results The results.
	 *
	 * @return void
	 */
	private function record(string $key, array $results): void {
		$path = $this->outDir() . '/results.json';
		$all = is_file($path) === true ? (array) json_decode((string) file_get_contents($path), true) : [];
		$all[$key] = $results;
		file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}//end record()

	/**
	 * The export tables both PDF engines render.
	 *
	 * @return array<string, list<string>> Case name to section fragments.
	 */
	private function pdfCases(): array {
		$section = new RowsPdfSection();
		$columns = ['id' => 'ID', 'name' => 'Naam', 'city' => 'Plaats', 'note' => 'Toelichting'];
		$row = static fn (int $i, string $name, string $city, string $note): array => ['id' => (string) $i, 'name' => $name, 'city' => $city, 'note' => $note];

		$ascii = [];
		for ($i = 1; $i <= 10; $i++) {
			$ascii[] = $row($i, 'Person ' . $i, 'Amsterdam', 'Plain ASCII row');
		}

		$many = [];
		for ($i = 1; $i <= 500; $i++) {
			$many[] = $row($i, 'Object ' . $i, 'Utrecht', 'Row ' . $i . ' of a long export');
		}

		$long = [];
		for ($i = 1; $i <= 5; $i++) {
			$long[] = $row($i, 'Long ' . $i, 'Den Haag', str_repeat('Een lange toelichting die over meerdere regels loopt. ', 4) . 'Einde' . $i);
		}

		return [
			'ascii-10-rows' => [$section->build(title: 'ASCII', columns: $columns, rows: $ascii)],
			'dutch-diacritics' => [$section->build(
				title: 'Bezwaar café',
				columns: $columns,
				rows: [
					$row(1, 'José Müller-Lüdenscheidt', 'Coevorden', 'Kosten € 1.250,– “inclusief” btw'),
					$row(2, 'Zoë van ’t Hek', 'Lemelerveld', 'Naïef, coöperatie, ëëë, ß, Ø'),
					$row(3, 'Łukasz Żółć', 'Gdańsk', 'Polish letters outside Windows-1252'),
				]
			),
			],
			'non-latin' => [$section->build(
				title: 'Non-Latin',
				columns: $columns,
				rows: [
					$row(1, 'Αλέξανδρος', 'Αθήνα', 'Greek'),
					$row(2, 'Дмитрий', 'Москва', 'Cyrillic'),
					$row(3, '山田太郎', '東京', 'CJK'),
					$row(4, 'Emoji 😀', 'Zwolle', 'Emoji ✓'),
				]
			),
			],
			'long-cells-wrap' => [$section->build(title: 'Long cells', columns: $columns, rows: $long)],
			'500-rows-multipage' => [$section->build(title: 'Many rows', columns: $columns, rows: $many)],
			'two-sections' => [
				$section->build(title: 'First schema', columns: $columns, rows: array_slice($ascii, 0, 3)),
				$section->build(title: 'Second schema', columns: ['a' => 'A', 'b' => 'B'], rows: [['a' => 'alpha', 'b' => 'beta']]),
			],
		];
	}//end pdfCases()

	/**
	 * Every cell text of the cases, to look for in the extracted text.
	 *
	 * @param list<string> $sections Section fragments.
	 *
	 * @return list<string>
	 */
	private function expectedCells(array $sections): array {
		$cells = [];
		foreach ($sections as $html) {
			preg_match_all('#<t[hd]>(.*?)</t[hd]>|<h1>(.*?)</h1>#s', $html, $matches, PREG_SET_ORDER);
			foreach ($matches as $match) {
				$cells[] = html_entity_decode($match[1] !== '' ? $match[1] : $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}

		return array_values(array_filter($cells, static fn (string $cell): bool => $cell !== ''));
	}//end expectedCells()

	/**
	 * Collapse all whitespace, for comparisons that ignore line wrapping.
	 *
	 * Invalid UTF-8 (a text layer can carry it) is replaced first, so one bad
	 * glyph does not empty the whole text.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function flat(string $text): string {
		return trim((string) preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')));
	}//end flat()

	/**
	 * Render every case with both engines and measure what the text layer keeps.
	 *
	 * @return void
	 */
	public function testPdfEngines(): void {
		$engines = ['dompdf' => new DompdfTableRenderer(), 'fpdf' => new FpdfTableRenderer()];
		$results = [];
		foreach ($this->pdfCases() as $case => $sections) {
			$expected = $this->expectedCells(sections: $sections);
			foreach ($engines as $name => $engine) {
				$results[$case][$name] = $this->measurePdf(engine: $engine, run: ['case' => $case, 'name' => $name, 'sections' => $sections, 'expected' => $expected]);
			}
		}

		$this->record(key: 'pdf', results: $results);
		$this->assertNotEmpty($results);
	}//end testPdfEngines()

	/**
	 * Render one case with one engine.
	 *
	 * @param PdfTableRenderer $engine The renderer.
	 * @param array{case: string, name: string, sections: list<string>, expected: list<string>} $run The case.
	 *
	 * @return array<string, mixed>
	 */
	private function measurePdf(PdfTableRenderer $engine, array $run): array {
		$start = hrtime(true);
		$bytes = $engine->render(sections: $run['sections']);
		$millis = (int) ((hrtime(true) - $start) / 1e6);
		file_put_contents($this->outDir() . '/' . $run['case'] . '-' . $run['name'] . '.pdf', $bytes);

		$document = (new Parser())->parseContent($bytes);
		$text = self::flat($document->getText());
		$missing = [];
		foreach ($run['expected'] as $cell) {
			if (str_contains($text, self::flat($cell)) === false) {
				$missing[] = $cell;
			}
		}

		$this->assertStringStartsWith('%PDF-', $bytes);

		return [
			'bytes' => strlen($bytes),
			'ms' => $millis,
			'pages' => count($document->getPages()),
			'cells' => count($run['expected']),
			'cellsFound' => count($run['expected']) - count($missing),
			'missingSample' => array_slice($missing, 0, 5),
		];
	}//end measurePdf()

	/**
	 * Build a DOCX and an ODT with headings, runs, a nested table, a list,
	 * header, footer, footnote, tab and line break, written by PhpWord.
	 *
	 * @return array<string, array{bytes: string, format: string, name: string}>
	 */
	private function generatedDocuments(): array {
		$word = new PhpWord();
		$word->addTitleStyle(1, ['bold' => true, 'size' => 16]);
		$section = $word->addSection();
		$section->addHeader()->addText('Kopregel gemeente Zuiddrecht');
		$section->addFooter()->addText('Voettekst pagina');
		$section->addTitle('Besluit op bezwaar', 1);
		$run = $section->addTextRun();
		$run->addText('Dit is ');
		$run->addText('vetgedrukt', ['bold' => true]);
		$run->addText(' en cursief', ['italic' => true]);
		$run->addText(' in één alinea.');
		$section->addText("Kolom A\tKolom B");
		$break = $section->addTextRun();
		$break->addText('Regel een');
		$break->addTextBreak();
		$break->addText('Regel twee');
		$section->addListItem('Eerste punt');
		$section->addListItem('Tweede punt');
		$table = $section->addTable();
		$table->addRow();
		$table->addCell(3000)->addText('Cel 1.1');
		$table->addCell(3000)->addText('Cel 1.2 met €');
		$table->addRow();
		$table->addCell(3000)->addText('Cel 2.1');
		$inner = $table->addCell(3000)->addTable();
		$inner->addRow();
		$inner->addCell(1500)->addText('Genest A');
		$inner->addCell(1500)->addText('Genest B');
		$noted = $section->addTextRun();
		$noted->addText('Zin met voetnoot');
		$noted->addFootnote()->addText('De voetnoottekst');
		$section->addText('Αλέξανδρος, Дмитрий, 山田太郎');

		$documents = [];
		foreach (['Word2007' => 'docx', 'ODText' => 'odt'] as $format => $extension) {
			$path = tempnam(sys_get_temp_dir(), 'cmp') . '.' . $extension;
			IOFactory::createWriter($word, $format)->save($path);
			$documents['generated.' . $extension] = ['bytes' => (string) file_get_contents($path), 'format' => $format, 'name' => 'generated.' . $extension];
			unlink($path);
		}

		return $documents;
	}//end generatedDocuments()

	/**
	 * The DOCX and ODT inputs: generated ones plus the real documents in the repository.
	 *
	 * @return array<string, array{bytes: string, format: string, name: string}>
	 */
	private function wordInputs(): array {
		$inputs = $this->generatedDocuments();
		foreach ([dirname(__DIR__) . '/Data/anonimisering_testdocument.docx', __DIR__ . '/fixtures/concept-besluit-demostad.docx'] as $path) {
			$inputs[basename($path)] = ['bytes' => (string) file_get_contents($path), 'format' => 'Word2007', 'name' => basename($path)];
		}

		return $inputs;
	}//end wordInputs()

	/**
	 * Extract every input with both readers and compare the words they return.
	 *
	 * @return void
	 */
	public function testWordReaders(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('native');
		$readers = ['phpword' => new WordExtractor(logger: $logger), 'native' => new WordExtractor(logger: $logger, appConfig: $config)];

		$results = [];
		foreach ($this->wordInputs() as $input) {
			$texts = [];
			foreach ($readers as $name => $reader) {
				$start = hrtime(true);
				$texts[$name] = (string) $reader->extract(file: $this->file(input: $input));
				$results[$input['name']][$name] = ['ms' => (int) ((hrtime(true) - $start) / 1e6), 'chars' => mb_strlen($texts[$name])];
				file_put_contents($this->outDir() . '/' . $input['name'] . '-' . $name . '.txt', $texts[$name]);
			}

			$results[$input['name']]['diff'] = $this->wordDiff(old: $texts['phpword'], new: $texts['native']);
			$this->assertNotSame('', $texts['native'], $input['name']);
		}

		$this->record(key: 'word', results: $results);
	}//end testWordReaders()

	/**
	 * A File mock for one input.
	 *
	 * @param array{bytes: string, format: string, name: string} $input The input.
	 *
	 * @return File
	 */
	private function file(array $input): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($input['bytes']);
		$file->method('getName')->willReturn($input['name']);
		$file->method('getId')->willReturn(1);
		$file->method('getMimeType')->willReturn('');

		return $file;
	}//end file()

	/**
	 * Compare two texts as bags of words.
	 *
	 * @param string $old Text from PhpWord.
	 * @param string $new Text from WordTextReader.
	 *
	 * @return array<string, mixed>
	 */
	private function wordDiff(string $old, string $new): array {
		$words = static function (string $text): array {
			preg_match_all('/[\p{L}\p{N}€]+/u', $text, $matches);
			return array_count_values($matches[0]);
		};
		$oldWords = $words($old);
		$newWords = $words($new);
		$onlyOld = [];
		$onlyNew = [];
		foreach ($oldWords + $newWords as $word => $_count) {
			$delta = ($newWords[$word] ?? 0) - ($oldWords[$word] ?? 0);
			if ($delta < 0) {
				$onlyOld[$word] = -$delta;
			} elseif ($delta > 0) {
				$onlyNew[$word] = $delta;
			}
		}

		return [
			'sameFlatText' => self::flat($old) === self::flat($new),
			'wordsOld' => array_sum($oldWords),
			'wordsNew' => array_sum($newWords),
			'onlyInPhpWord' => array_slice($onlyOld, 0, 15, true),
			'onlyInNative' => array_slice($onlyNew, 0, 15, true),
		];
	}//end wordDiff()
}//end class
