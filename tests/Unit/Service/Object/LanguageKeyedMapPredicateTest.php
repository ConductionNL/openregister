<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins what the save path (MetadataHydrationHandler) and the translation path
 * (TranslationHandler) both call a language-keyed map.
 *
 * @covers \OCA\OpenRegister\Service\Object\TranslationHandler
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler
 * @uses   \OCA\OpenRegister\Service\Object\LanguageKeyedMap
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\LanguageMapText
 */
class LanguageKeyedMapPredicateTest extends TestCase {

	/**
	 * @return array<string, array{array<mixed>, bool}>
	 */
	public static function maps(): array {
		return [
			'empty'            => [[], false],
			'nl en'            => [['nl' => 'a', 'en' => 'b'], true],
			'three letters'    => [['nld' => 'a'], true],
			'region'           => [['en-US' => 'a'], true],
			'script region'    => [['zh-Hant-TW' => 'a'], true],
			'upper language'   => [['EN' => 'a'], false],
			'one letter'       => [['e' => 'a'], false],
			'four letters'     => [['engl' => 'a'], false],
			'long subtag'      => [['en-abcdefghi' => 'a'], false],
			'short subtag'     => [['en-a' => 'a'], false],
			'underscore'       => [['en_US' => 'a'], false],
			'list'             => [['a', 'b'], false],
			'mixed keys'       => [['nl' => 'a', 0 => 'b'], false],
			'one bad key'      => [['nl' => 'a', 'title' => 'b'], false],
			'trailing newline' => [["nl\n" => 'a'], true],
		];
	}//end maps()

	/**
	 * @dataProvider maps
	 */
	public function testBothPathsAgree(array $value, bool $expected): void {
		$this->assertSame([$expected, $expected], [
			$this->call(TranslationHandler::class, $value),
			$this->call(MetadataHydrationHandler::class, $value),
		]);
	}//end testBothPathsAgree()

	private function call(string $class, array $value): bool {
		$object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod($object, 'isLanguageKeyedObject');
		$method->setAccessible(true);
		return $method->invoke($object, $value);
	}//end call()
}//end class
