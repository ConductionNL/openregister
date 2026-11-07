<?php

/**
 * PresentationExtractorGoldenTest
 *
 * Golden tests that pin the exact result and log lines of
 * {@see \OCA\OpenRegister\Files\Service\TextExtraction\PresentationExtractor::extract()}
 * today: the full slide list of a deck with skipped and unreadable slides,
 * notes, the default presentation path, refused parts, the slide cap, and
 * every way the read degrades to null.
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

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\PresentationExtractor;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ZipArchive;

/**
 * Pins PresentationExtractor::extract().
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\PresentationExtractor
 */
class PresentationExtractorGoldenTest extends TestCase {

	private const MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

	private const NS = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
		.' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
		.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

	private const TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

	/**
	 * Log lines as "level message {context without line}".
	 *
	 * @var list<string>
	 */
	public array $logLines = [];

	/**
	 * Zip parts into bytes.
	 *
	 * @param array<string,string> $parts Part path => content.
	 *
	 * @return string
	 */
	private static function zip(array $parts): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'or-pptx');
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string) file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end zip()

	/**
	 * A relationships part.
	 *
	 * @param list<array{0:string,1:string,2:string,3?:bool}> $rels [Id, type suffix, target, external].
	 *
	 * @return string
	 */
	private static function rels(array $rels): string {
		$xml = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ($rels as $rel) {
			$mode = '';
			if (($rel[3] ?? false) === true) {
				$mode = ' TargetMode="External"';
			}

			$xml .= '<Relationship Id="'.$rel[0].'" Type="'.self::TYPE.$rel[1].'" Target="'.$rel[2].'"'.$mode.'/>';
		}

		return $xml.'</Relationships>';
	}//end rels()

	/**
	 * A presentation part listing slide relationship ids.
	 *
	 * @param list<string> $ids Relationship ids in deck order.
	 *
	 * @return string
	 */
	private static function presentation(array $ids): string {
		$list = '';
		foreach ($ids as $i => $id) {
			$list .= '<p:sldId id="'.(256 + $i).'" r:id="'.$id.'"/>';
		}

		return '<p:presentation '.self::NS.'><p:sldIdLst>'.$list.'</p:sldIdLst></p:presentation>';
	}//end presentation()

	/**
	 * A slide with a title and a body paragraph.
	 *
	 * @param string $title Title text.
	 * @param string $body  Body text.
	 * @param string $attr  Root attributes.
	 *
	 * @return string
	 */
	private static function slide(string $title, string $body, string $attr = ''): string {
		return '<p:sld '.self::NS.$attr.'><p:cSld><p:spTree>'
			.'<p:sp><p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.$title.'</a:t></a:r></a:p></p:txBody></p:sp>'
			.'<p:sp><p:nvSpPr><p:nvPr/></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.$body.'</a:t></a:r></a:p></p:txBody></p:sp>'
			.'<p:pic><p:nvPicPr><p:cNvPr id="3" name="Foto" descr="Alt"/></p:nvPicPr><p:blipFill><a:blip r:embed="img"/></p:blipFill></p:pic>'
			.'</p:spTree></p:cSld></p:sld>';
	}//end slide()

	/**
	 * A notes page.
	 *
	 * @param string $text Notes text.
	 *
	 * @return string
	 */
	private static function notes(string $text): string {
		return '<p:notes '.self::NS.'><p:cSld><p:spTree><p:sp><p:nvSpPr><p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr>'
			.'<p:txBody><a:p><a:r><a:t>'.$text.'</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:notes>';
	}//end notes()

	/**
	 * Extract from bytes with a MIME type and name.
	 *
	 * @param string $bytes The file bytes.
	 * @param string $mime  The MIME type.
	 * @param string $name  The file name.
	 *
	 * @return array|null
	 */
	private function extract(string $bytes, string $mime = self::MIME, string $name = 'les.pptx'): ?array {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(9);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($bytes);
		$test   = $this;
		$logger = new class ($test) extends AbstractLogger {
			public function __construct(private readonly PresentationExtractorGoldenTest $test) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				unset($context['line']);
				$context['file'] = basename((string) $context['file']);
				$this->test->logLines[] = $level.' '.$message.' '.json_encode($context);
			}
		};

		return (new PresentationExtractor($logger))->extract($file);
	}//end extract()

	/**
	 * A deck with skipped, unreadable and noted slides reads to the pinned result.
	 *
	 * @return void
	 */
	public function testDeck(): void {
		$bytes = self::zip(
			[
				'_rels/.rels'                          => self::rels([['rId1', 'officeDocument', 'ppt/deck.xml']]),
				'ppt/deck.xml'                         => self::presentation(['s2', 'ext', 'nope', 's1', 'bad', 's3']),
				'ppt/_rels/deck.xml.rels'              => self::rels(
					[
						['s1', 'slide', 'slides/slide1.xml'],
						['s2', 'slide', 'slides/slide2.xml'],
						['s3', 'slide', 'slides/slide3.xml'],
						['bad', 'slide', 'slides/missing.xml'],
						['ext', 'slide', 'https://example.org/s.xml', true],
					]
				),
				'ppt/slides/slide1.xml'                => self::slide('Een', 'eerste'),
				'ppt/slides/_rels/slide1.xml.rels'     => self::rels(
					[
						['img', 'image', '../media/a.png'],
						['n0', 'notesSlide', 'https://example.org/n.xml', true],
						['n1', 'notesSlide', '../notesSlides/notesSlide1.xml'],
						['n2', 'notesSlide', '../notesSlides/notesSlide2.xml'],
					]
				),
				'ppt/slides/slide2.xml'                => self::slide('Twee', 'tweede', ' show="0"'),
				'ppt/slides/_rels/slide2.xml.rels'     => self::rels([['n1', 'notesSlide', '../notesSlides/gone.xml'], ['n2', 'notesSlide', '../notesSlides/notesSlide2.xml']]),
				'ppt/slides/slide3.xml'                => '<?xml version="1.0"?><!DOCTYPE x><p:sld '.self::NS.'/>',
				'ppt/notesSlides/notesSlide1.xml'      => self::notes('Notitie een'),
				'ppt/notesSlides/notesSlide2.xml'      => self::notes('Notitie twee'),
			]
		);

		$empty = ['hidden' => false, 'title' => '', 'body' => [], 'notes' => '', 'images' => []];
		self::assertSame(
			[
				'slides'    => [
					[
						'number' => 1, 'hidden' => true, 'title' => 'Twee', 'body' => ['tweede'], 'notes' => '',
						'images' => [['target' => '', 'external' => false, 'name' => 'Foto', 'description' => 'Alt']],
					],
					[
						'number' => 4, 'hidden' => false, 'title' => 'Een', 'body' => ['eerste'], 'notes' => 'Notitie een',
						'images' => [['target' => 'ppt/media/a.png', 'external' => false, 'name' => 'Foto', 'description' => 'Alt']],
					],
					['number' => 5] + $empty,
					['number' => 6] + $empty,
				],
				'truncated' => false,
			],
			$this->extract($bytes)
		);
		self::assertSame(
			[
				'warning [PresentationExtractor] Refused parts that were too large, declared a DOCTYPE or were not XML {"file":"PresentationExtractor.php","fileId":9,"parts":["ppt\/slides\/slide3.xml"]}',
				'debug [PresentationExtractor] Presentation extracted {"file":"PresentationExtractor.php","fileId":9,"slides":4,"truncated":false}',
			],
			$this->logLines
		);
	}//end testDeck()

	/**
	 * Without a package relationship the default presentation path is read;
	 * past the slide cap the result is truncated.
	 *
	 * @return void
	 */
	public function testDefaultPathAndCap(): void {
		$ids   = array_fill(0, (PresentationExtractor::MAX_SLIDES + 2), 's');
		$bytes = self::zip(
			[
				'ppt/presentation.xml'              => self::presentation($ids),
				'ppt/_rels/presentation.xml.rels'   => self::rels([['s', 'slide', 'slides/s.xml']]),
				'ppt/slides/s.xml'                  => self::slide('T', 'b'),
			]
		);

		$result = $this->extract($bytes, 'application/octet-stream', 'deck.PPSX');
		self::assertNotNull($result);
		self::assertTrue($result['truncated']);
		self::assertCount(PresentationExtractor::MAX_SLIDES, $result['slides']);
		self::assertSame(PresentationExtractor::MAX_SLIDES, $result['slides'][(PresentationExtractor::MAX_SLIDES - 1)]['number']);
		self::assertSame(['debug [PresentationExtractor] Presentation extracted {"file":"PresentationExtractor.php","fileId":9,"slides":500,"truncated":true}'], $this->logLines);

		$this->logLines = [];
		$exact = self::zip(
			[
				'ppt/presentation.xml'            => self::presentation(array_fill(0, PresentationExtractor::MAX_SLIDES, 's')),
				'ppt/_rels/presentation.xml.rels' => self::rels([['s', 'slide', 'slides/s.xml']]),
				'ppt/slides/s.xml'                => self::slide('T', 'b'),
			]
		);
		self::assertFalse($this->extract($exact)['truncated']);
	}//end testDefaultPathAndCap()

	/**
	 * Every way extract() degrades to null, with its log line.
	 *
	 * @return void
	 */
	public function testNullOutcomes(): void {
		$cases = [
			'unsupported mime' => [self::zip(['a' => 'b']), 'application/pdf', 'x.pptx'],
			'generic, wrong ext' => [self::zip(['a' => 'b']), 'application/zip', 'x.docx'],
			'not a zip'        => ['plain text, not a package', self::MIME, 'x.pptx'],
			'no presentation'  => [self::zip(['other.xml' => '<a/>']), self::MIME, 'x.pptx'],
			'no slides'        => [self::zip(['ppt/presentation.xml' => self::presentation([])]), self::MIME, 'x.pptx'],
			'only external'    => [
				self::zip(['ppt/presentation.xml' => self::presentation(['e']), 'ppt/_rels/presentation.xml.rels' => self::rels([['e', 'slide', 'https://x/s.xml', true]])]),
				self::MIME,
				'x.pptx',
			],
			'refused main'     => [self::zip(['ppt/presentation.xml' => 'not xml']), self::MIME, 'x.pptx'],
		];
		foreach ($cases as $label => [$bytes, $mime, $name]) {
			self::assertNull($this->extract($bytes, $mime, $name), $label);
		}

		$m = '"mimeType":"'.str_replace('/', '\/', self::MIME).'"';
		self::assertSame(
			[
				'debug [PresentationExtractor] Not a presentation format this extractor reads {"file":"PresentationExtractor.php","fileId":9,"mimeType":"application\/pdf"}',
				'debug [PresentationExtractor] Not a presentation format this extractor reads {"file":"PresentationExtractor.php","fileId":9,"mimeType":"application\/zip"}',
				'error [PresentationExtractor] Presentation extraction failed; returning null {"file":"PresentationExtractor.php","fileId":9,'.$m.',"exception":"RuntimeException"}',
				'warning [PresentationExtractor] Presentation holds no readable slides {"file":"PresentationExtractor.php","fileId":9,'.$m.'}',
				'warning [PresentationExtractor] Presentation holds no readable slides {"file":"PresentationExtractor.php","fileId":9,'.$m.'}',
				'warning [PresentationExtractor] Presentation holds no readable slides {"file":"PresentationExtractor.php","fileId":9,'.$m.'}',
				'warning [PresentationExtractor] Refused parts that were too large, declared a DOCTYPE or were not XML {"file":"PresentationExtractor.php","fileId":9,"parts":["ppt\/presentation.xml"]}',
				'warning [PresentationExtractor] Presentation holds no readable slides {"file":"PresentationExtractor.php","fileId":9,'.$m.'}',
			],
			$this->logLines
		);
	}//end testNullOutcomes()
}//end class
