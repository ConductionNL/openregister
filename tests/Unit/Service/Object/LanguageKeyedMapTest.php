<?php

/**
 * Unit tests for recognising a map keyed by language tags.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\LanguageKeyedMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only a non-empty map whose every key is a language tag counts.
 *
 * @covers \OCA\OpenRegister\Service\Object\LanguageKeyedMap
 */
class LanguageKeyedMapTest extends TestCase {

	/**
	 * Values and whether they are language-keyed.
	 *
	 * @return array<string, array{0: array<mixed>, 1: bool}>
	 */
	public static function maps(): array {
		return [
			'empty'              => [[], false],
			'nl and en'          => [['nl' => 'Hallo', 'en' => 'Hello'], true],
			'region tag'         => [['en-GB' => 'Colour', 'pt-BR' => 'Cor'], true],
			'three letters'      => [['fry' => 'Hallo'], true],
			'a list'             => [['Hallo', 'Hello'], false],
			'one non-tag key'    => [['nl' => 'Hallo', 'title' => 'x'], false],
			'upper-case primary' => [['NL' => 'Hallo'], false],
		];

	}//end maps()

	/**
	 * Each map is judged by its keys.
	 *
	 * @param array<mixed> $value    The map.
	 * @param bool         $expected Whether it is language-keyed.
	 *
	 * @return void
	 */
	#[DataProvider('maps')]
	public function testRecognisesLanguageKeyedMaps(array $value, bool $expected): void {
		$this->assertSame($expected, (new LanguageKeyedMap($value))->isLanguageKeyed());

	}//end testRecognisesLanguageKeyedMaps()
}//end class
