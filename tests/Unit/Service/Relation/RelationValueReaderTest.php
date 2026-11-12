<?php

/**
 * Unit test for RelationValueReader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Service\Relation\RelationValueReader;
use PHPUnit\Framework\TestCase;

/**
 * How declared labels, titles and keys are read.
 */
class RelationValueReaderTest extends TestCase {

	/**
	 * A label resolves by exact tag, primary subtag, Dutch, English, then anything; blank is nothing.
	 *
	 * @return void
	 */
	public function testALabelResolvesInTheDocumentedOrder(): void {
		$reader = new RelationValueReader();
		$map    = (object)['fr' => ' fr ', 'en' => 'en', 'nl' => 'nl', 'de' => ' ', 'de-AT' => 'at'];

		$this->assertSame('at', $reader->text(value: $map, language: 'de-AT'));
		$this->assertSame('nl', $reader->text(value: $map, language: 'de-CH'));
		$this->assertSame('fr', $reader->text(value: ['fr' => ' fr ', 'x' => 'x'], language: 'nl'));
		$this->assertSame('plain', $reader->text(value: ' plain ', language: 'en'));
		$this->assertNull($reader->text(value: '  ', language: 'en'));
		$this->assertNull($reader->text(value: 42, language: 'en'));
		$this->assertNull($reader->text(value: ['a' => 1, 'b' => ' '], language: 'en'));
	}//end testALabelResolvesInTheDocumentedOrder()

	/**
	 * A title is read off an array or a decoded object, trimmed; anything else has none.
	 *
	 * @return void
	 */
	public function testATitleIsReadOffArraysAndObjects(): void {
		$reader = new RelationValueReader();

		$this->assertSame('T', $reader->titleOf(property: ['title' => ' T ']));
		$this->assertSame('T', $reader->titleOf(property: (object)['title' => 'T']));
		$this->assertNull($reader->titleOf(property: ['title' => '']));
		$this->assertNull($reader->titleOf(property: 'string'));
		$this->assertSame(['a' => 1], $reader->asArray(value: (object)['a' => 1]));
		$this->assertSame('s', $reader->asArray(value: 's'));
		$this->assertNull($reader->nonBlank(value: 3));
	}//end testATitleIsReadOffArraysAndObjects()
}//end class
