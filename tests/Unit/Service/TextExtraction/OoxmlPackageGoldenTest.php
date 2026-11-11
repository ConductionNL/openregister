<?php

/**
 * OoxmlPackageGoldenTest
 *
 * Golden tests that pin what
 * {@see \OCA\OpenRegister\Files\Service\TextExtraction\OoxmlPackage} reads,
 * refuses and resolves today: the part-size bound, DOCTYPE refusal in UTF-8
 * and UTF-16, case-insensitive lookup, relationship paths and target
 * resolution, and the main-part lookup.
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

use OCA\OpenRegister\Files\Service\TextExtraction\OoxmlPackage;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pins OoxmlPackage's reads and relationship resolution.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\OoxmlPackage
 */
class OoxmlPackageGoldenTest extends TestCase {

	private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

	private const TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

	/**
	 * Temp files to remove.
	 *
	 * @var list<string>
	 */
	private array $paths = [];

	/**
	 * Remove temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			@unlink($path);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Open a package holding the given parts.
	 *
	 * @param array<string,string> $parts    Part path => bytes.
	 * @param int                  $maxBytes The part-size bound.
	 *
	 * @return OoxmlPackage
	 */
	private function package(array $parts, int $maxBytes): OoxmlPackage {
		$path          = (string) tempnam(sys_get_temp_dir(), 'or-ooxml');
		$this->paths[] = $path;
		$zip           = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();
		$zip->open($path, ZipArchive::RDONLY);
		return new OoxmlPackage(zip: $zip, maxPartBytes: $maxBytes);
	}//end package()

	/**
	 * A relationships part.
	 *
	 * @param list<array{0:string,1:string,2:string,3?:string}> $rels [Id, type suffix, target, TargetMode].
	 *
	 * @return string
	 */
	private static function rels(array $rels): string {
		$xml = '<?xml version="1.0"?><Relationships xmlns="'.self::REL_NS.'">';
		foreach ($rels as $rel) {
			$mode = '';
			if (isset($rel[3]) === true) {
				$mode = ' TargetMode="'.$rel[3].'"';
			}

			$xml .= '<Relationship Id="'.$rel[0].'" Type="'.self::TYPE.$rel[1].'" Target="'.$rel[2].'"'.$mode.'/>';
		}

		return $xml.'</Relationships>';
	}//end rels()

	/**
	 * Which parts read, which are refused, and in what order refusals are recorded.
	 *
	 * @return void
	 */
	public function testReadXmlBoundsAndRefusals(): void {
		$exact   = '<a>'.str_repeat('x', 93).'</a>';
		$utf16   = mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE a [<!ENTITY e "x">]><a>&e;</a>', 'UTF-16LE', 'UTF-8');
		$package = $this->package(
			[
				'ok.xml'      => '<root><child>t</child></root>',
				'Mixed/Case.XML' => '<m/>',
				'exact.xml'   => $exact,
				'big.xml'     => $exact.' ',
				'empty.xml'   => '',
				'doctype.xml' => '<?xml version="1.0"?><!doctype a><a/>',
				'broken.xml'  => '<a><b></a>',
				'text.xml'    => 'just text',
				'utf16.xml'   => "\xFF\xFE".$utf16,
				'comment.xml' => '<!-- only a comment -->',
			],
			100
		);

		self::assertSame(100, strlen($exact));
		self::assertSame('root', $package->readXml(path: 'ok.xml')?->documentElement?->nodeName);
		self::assertSame('m', $package->readXml(path: 'mixed/case.xml')?->documentElement?->nodeName);
		self::assertSame('a', $package->readXml(path: 'exact.xml')?->documentElement?->nodeName);
		self::assertNull($package->readXml(path: 'missing.xml'));
		self::assertNull($package->readXml(path: 'empty.xml'));
		self::assertSame([], $package->refusedParts());

		foreach (['big.xml', 'doctype.xml', 'broken.xml', 'text.xml', 'utf16.xml', 'comment.xml', 'big.xml'] as $path) {
			self::assertNull($package->readXml(path: $path), $path);
		}

		self::assertSame(['big.xml', 'doctype.xml', 'broken.xml', 'text.xml', 'utf16.xml', 'comment.xml', 'big.xml'], $package->refusedParts());
	}//end testReadXmlBoundsAndRefusals()

	/**
	 * Relationship paths, target resolution and the external flag.
	 *
	 * @return void
	 */
	public function testRelationships(): void {
		$package = $this->package(
			[
				'_rels/.rels'                       => self::rels([['rId1', 'officeDocument', 'ppt/presentation.xml']]),
				'ppt/slides/_rels/slide1.xml.rels'  => self::rels(
					[
						['a', 'image', '../media/image1.png'],
						['b', 'image', '/word/abs.xml'],
						['c', 'image', 'my%20file.png'],
						['d', 'image', './x/./y//z.png'],
						['e', 'image', '../../../escape.png'],
						['f', 'hyperlink', 'https://example.org/p?q=1', 'External'],
						['g', 'image', '../../top.png'],
						['h', 'image', 'x/../../..'],
						['a', 'image', 'dup.png'],
						['i', 'image', ''],
						['j', 'image', 'a/b/../../../..', 'Internal'],
						['k', 'image', 'a/../../../../x.png'],
					]
				),
				'_rels/doc.xml.rels'                => self::rels([['r', 'header', 'header1.xml']]),
				'ppt/_rels/presentation.xml.rels'   => 'not xml',
			],
			10000
		);

		self::assertSame(['rId1' => ['type' => self::TYPE.'officeDocument', 'target' => 'ppt/presentation.xml', 'external' => false]], $package->relationships(partPath: ''));
		$img = self::TYPE.'image';
		self::assertSame(
			[
				'a' => ['type' => $img, 'target' => 'ppt/slides/dup.png', 'external' => false],
				'b' => ['type' => $img, 'target' => 'word/abs.xml', 'external' => false],
				'c' => ['type' => $img, 'target' => 'ppt/slides/my file.png', 'external' => false],
				'd' => ['type' => $img, 'target' => 'ppt/slides/x/y/z.png', 'external' => false],
				'e' => ['type' => $img, 'target' => '../../../escape.png', 'external' => true],
				'f' => ['type' => self::TYPE.'hyperlink', 'target' => 'https://example.org/p?q=1', 'external' => true],
				'g' => ['type' => $img, 'target' => 'top.png', 'external' => false],
				'h' => ['type' => $img, 'target' => '', 'external' => false],
				'i' => ['type' => $img, 'target' => 'ppt/slides', 'external' => false],
				'j' => ['type' => $img, 'target' => '', 'external' => false],
				'k' => ['type' => $img, 'target' => 'a/../../../../x.png', 'external' => true],
			],
			$package->relationships(partPath: 'ppt/slides/slide1.xml')
		);
		self::assertSame(['r' => ['type' => self::TYPE.'header', 'target' => 'header1.xml', 'external' => false]], $package->relationships(partPath: 'doc.xml'));
		self::assertSame([], $package->relationships(partPath: 'ppt/presentation.xml'));
		self::assertSame([], $package->relationships(partPath: 'nope/none.xml'));
		self::assertSame(['ppt/_rels/presentation.xml.rels'], $package->refusedParts());
		self::assertSame('ppt/presentation.xml', $package->mainPartPath());
	}//end testRelationships()

	/**
	 * The main part is the first internal officeDocument relationship.
	 *
	 * @return void
	 */
	public function testMainPartPath(): void {
		$rels = self::rels(
			[
				['r0', 'image', 'x.xml'],
				['r1', 'officeDocument', 'https://example.org/doc.xml', 'External'],
				['r2', 'officeDocument', '../../out.xml'],
				['r3', 'officeDocument', 'word/document.xml'],
				['r4', 'officeDocument', 'word/second.xml'],
			]
		);
		self::assertSame('word/document.xml', $this->package(['_rels/.rels' => $rels], 10000)->mainPartPath());
		self::assertNull($this->package(['_rels/.rels' => self::rels([['r0', 'image', 'x.xml']])], 10000)->mainPartPath());
		self::assertNull($this->package(['other.xml' => '<a/>'], 10000)->mainPartPath());
	}//end testMainPartPath()
}//end class
