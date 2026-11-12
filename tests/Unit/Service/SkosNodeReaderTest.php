<?php

/**
 * SkosNodeReader: reading predicates, literals, language maps and id references
 * out of expanded or compacted JSON-LD SKOS nodes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\SkosNodeReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SkosNodeReader
 */
class SkosNodeReaderTest extends TestCase {

	private SkosNodeReader $reader;

	protected function setUp(): void {
		parent::setUp();
		$this->reader = new SkosNodeReader();
	}

	public function testLocalNameCutsAtTheLastHashSlashOrColon(): void {
		$this->assertSame('prefLabel', $this->reader->localName('http://www.w3.org/2004/02/skos/core#prefLabel'));
		$this->assertSame('broader', $this->reader->localName('http://example.org/vocab/broader'));
		$this->assertSame('notation', $this->reader->localName('skos:notation'));
		$this->assertSame('plain', $this->reader->localName('plain'));
	}

	public function testFindPredicateSkipsKeywordsAndMatchesOnLocalName(): void {
		$node = ['@id' => 'x', '@prefLabel' => 'keyword', 'skos:prefLabel' => 'Label', 'http://e/prefLabel' => 'second'];

		$this->assertSame('Label', $this->reader->findPredicate($node, 'prefLabel'));
		$this->assertNull($this->reader->findPredicate($node, 'altLabel'));
	}

	public function testLiteralFromStrings(): void {
		$this->assertSame('Zaak', $this->reader->literal('  Zaak '));
		$this->assertNull($this->reader->literal('   '));
		$this->assertNull($this->reader->literal(42));
	}

	public function testLiteralFromValueObjectsAndLists(): void {
		$this->assertSame('7', $this->reader->literal(['@value' => 7]));
		$this->assertSame('second', $this->reader->literal(['  ', ['@id' => 5], ['@value' => 'second'], 'third']));
		$this->assertNull($this->reader->literal([' ', 5]));
		$this->assertNull($this->reader->literal([]));
	}

	public function testLangMapFromAStringIsDutch(): void {
		$this->assertSame(['nl' => 'Huis'], $this->reader->langMap(' Huis '));
		$this->assertSame([], $this->reader->langMap(''));
		$this->assertSame([], $this->reader->langMap(null));
	}

	public function testLangMapFromASingleValueObjectUsesItsLanguageOrDutch(): void {
		$this->assertSame(['en' => 'House'], $this->reader->langMap(['@value' => 'House', '@language' => 'en']));
		$this->assertSame(['nl' => 'Huis'], $this->reader->langMap(['@value' => 'Huis']));
	}

	public function testLangMapFromAListKeepsValueObjectsAndPlainStrings(): void {
		$map = $this->reader->langMap([
			['@value' => 'House', '@language' => 'en'],
			['@language' => 'de'],
			'  ',
			3,
			'Huis',
		]);

		$this->assertSame(['en' => 'House', 'nl' => 'Huis'], $map);
	}

	public function testLangMapFromALanguageKeyedMapDropsBlankLabels(): void {
		$this->assertSame(['en' => 'House'], $this->reader->langMap(['en' => 'House', 'de' => ' ', 'fr' => ['x']]));
	}

	public function testIdRefsFromStringsAndSingleReferences(): void {
		$this->assertSame(['http://e/1'], $this->reader->idRefs(' http://e/1 '));
		$this->assertSame([], $this->reader->idRefs(' '));
		$this->assertSame([], $this->reader->idRefs(12));
		$this->assertSame(['http://e/2'], $this->reader->idRefs(['@id' => 'http://e/2']));
	}

	public function testIdRefsFromAListKeepReferencesAndNonBlankStrings(): void {
		$refs = $this->reader->idRefs([['@id' => 'a'], ['@value' => 'not a ref'], 'b', ' ', 9]);

		$this->assertSame(['a', 'b'], $refs);
	}
}
