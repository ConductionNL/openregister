<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\VocabularyCsvReader;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Golden test for VocabularyCsvReader: pins the scheme node and the concept
 * nodes read from CSV files written to a private temp directory.
 *
 * @covers \OCA\OpenRegister\Service\VocabularyCsvReader
 */
final class VocabularyCsvReaderGoldenTest extends TestCase {

	/** @var array<int,string> Files written by this test */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}

	private function csv(string $content): string {
		$file = (string)tempnam(sys_get_temp_dir(), 'or-vocab-csv-');
		file_put_contents($file, $content);
		$this->files[] = $file;
		return $file;
	}

	public function testSchemeNodeDefaults(): void {
		$reader = new VocabularyCsvReader();
		$this->assertSame(
			[
				'@id' => 'https://ex.test/s',
				'dct:title' => 'https://ex.test/s',
				'dct:publisher' => '',
				'owl:versionInfo' => '1.0',
				'dct:source' => 'https://ex.test/s',
			],
			$reader->schemeNode(['uri' => 'https://ex.test/s', 'title' => null])
		);
		$this->assertSame(
			[
				'@id' => ' u ',
				'dct:title' => 'T',
				'dct:publisher' => 'P',
				'owl:versionInfo' => '2',
				'dct:source' => 'S',
			],
			$reader->schemeNode(['uri' => ' u ', 'title' => 'T', 'publisher' => 'P', 'version' => '2', 'source' => 'S', 'x' => 'y'])
		);
	}

	public function testSchemeNodeRequiresAUri(): void {
		foreach ([[], ['uri' => null], ['uri' => 5], ['uri' => '  ']] as $index => $meta) {
			try {
				(new VocabularyCsvReader())->schemeNode($meta);
				$this->fail('no exception for case ' . $index);
			} catch (InvalidArgumentException $e) {
				$this->assertSame('CSV import requires a non-empty schemeMeta["uri"].', $e->getMessage());
			}
		}
	}

	public function testConceptNodes(): void {
		$file = $this->csv(
			" uri , notation,definition,prefLabel_nl,prefLabel_en,broaderUri\n"
			. "u:1,N1,Def,Een, ,\n"
			. "\n"
			. ",N2,no uri,Twee,Two,\n"
			. "  u:3  ,,,\" Drie \",Three,u:1| u:2 \n"
			. "u:4\n"
			. "\"u:5\",\"a,b\",\"say \"\"hi\"\"\",Vijf,Five,|\n"
		);

		$this->assertSame(
			[
				[
					'@id' => 'u:1',
					'skos:notation' => 'N1',
					'skos:definition' => 'Def',
					'skos:prefLabel' => ['nl' => 'Een'],
					'skos:broader' => [],
				],
				[
					'@id' => 'u:3',
					'skos:notation' => '',
					'skos:definition' => '',
					'skos:prefLabel' => ['nl' => 'Drie', 'en' => 'Three'],
					'skos:broader' => [['@id' => 'u:1'], ['@id' => 'u:2']],
				],
				[
					'@id' => 'u:4',
					'skos:notation' => null,
					'skos:definition' => null,
					'skos:prefLabel' => [],
					'skos:broader' => [],
				],
				[
					'@id' => 'u:5',
					'skos:notation' => 'a,b',
					'skos:definition' => 'say "hi"',
					'skos:prefLabel' => ['nl' => 'Vijf', 'en' => 'Five'],
					'skos:broader' => [['@id' => ''], ['@id' => '']],
				],
			],
			(new VocabularyCsvReader())->conceptNodes($file)
		);
	}

	public function testHeaderOnlyFileHasNoConcepts(): void {
		$this->assertSame([], (new VocabularyCsvReader())->conceptNodes($this->csv("uri,notation\n")));
	}

	public function testEmptyFileIsRefused(): void {
		$file = $this->csv('');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('CSV value-list file is empty: ' . $file);
		(new VocabularyCsvReader())->conceptNodes($file);
	}

	public function testMissingFileIsRefused(): void {
		$missing = sys_get_temp_dir() . '/or-vocab-csv-missing-' . bin2hex(random_bytes(4)) . '.csv';
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('CSV value-list file not found: ' . $missing);
		(new VocabularyCsvReader())->conceptNodes($missing);
	}

	public function testDirectoryIsRefusedAsMissing(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('CSV value-list file not found: ' . sys_get_temp_dir());
		(new VocabularyCsvReader())->conceptNodes(sys_get_temp_dir());
	}

	public function testRowWiderThanHeaderIsAValueError(): void {
		$this->expectException(\ValueError::class);
		(new VocabularyCsvReader())->conceptNodes($this->csv("uri,notation\nu:1,N,extra\n"));
	}

	public function testBlankHeaderLineIsATypeError(): void {
		$this->expectException(TypeError::class);
		(new VocabularyCsvReader())->conceptNodes($this->csv("\nuri\nu:1\n"));
	}
}
