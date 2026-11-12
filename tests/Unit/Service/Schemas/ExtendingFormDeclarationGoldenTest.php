<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case calls one public method; the result is compared with
 * fixtures/extending-form-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * What depends on the size of the published vocabulary (the narrowing list and
 * its counts) is checked against the vocabulary itself rather than recorded,
 * so adding a vocabulary key does not break this test.
 */
class ExtendingFormDeclarationGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/extending-form-golden.json';

	/**
	 * @return array<string, array{0: string, 1: array<int, mixed>}>
	 */
	public static function cases(): array {
		$base = ['app' => 'dossiq', 'form' => 'case', 'definitions' => 'caseDefinitions'];
		$map = $base + ['map' => ['title' => 'name', 'type' => 'propertyType', 'definition' => 'text', 'description' => 'text', 'hideOnCreate' => 'auto', 3 => 'x']];
		$forwards = $base + ['forwards' => ['title', 'type', 7, 'title', 'colour']];
		$annotations = [
			'not an object' => 'x',
			'null' => null,
			'empty' => [],
			'two spellings' => $base + ['map' => [], 'forwards' => []],
			'forwards nothing' => $base,
			'no definitions' => ['map' => ['title' => 'name']],
			'empty definitions' => ['map' => ['title' => 'name'], 'definitions' => ''],
			'list definitions empty' => ['forwards' => ['title'], 'definitions' => []],
			'app and form not strings' => ['app' => 5, 'form' => ['x'], 'map' => ['title' => 'name'], 'definitions' => 'd'],
			'app wrong and two spellings' => ['app' => 5, 'map' => [], 'forwards' => []],
			'map' => $map,
			'map bad fields' => $base + ['map' => ['title' => '', 'type' => 5, 'colour' => 'c', 'shade' => null]],
			'forwards' => $forwards,
			'empty map' => $base + ['map' => []],
			'map not array forwards array' => $base + ['map' => 'x', 'forwards' => ['enum']],
			'source alias only' => $base + ['map' => ['definition' => 'd']],
			'alias and description' => $base + ['map' => ['description' => 'd', 'definition' => 'e']],
		];
		$cases = [];
		foreach ($annotations as $name => $annotation) {
			$cases['validate ' . $name] = ['validate', [$annotation, '/p']];
			$cases['forwards ' . $name] = ['forwards', [$annotation]];
			$cases['describe ' . $name] = ['describe', [$annotation, '/p']];
		}

		// describe() casts a non-string app or form with (string), which warns
		// "Array to string conversion"; that case is pinned through validate() only.
		unset($cases['describe app and form not strings']);

		$cases['validate default path'] = ['validate', ['x']];
		$cases['describe default path'] = ['describe', [$map]];
		$cases['fromSchema none'] = ['fromSchema', [[], []]];
		$cases['fromSchema both'] = ['fromSchema', [['x-openregister-extends-form' => $map], ['a' => ['type' => 'string'], 'b' => ['x-openregister-extends-form' => 'scalar'], 'c' => 'skip', 4 => ['x-openregister-extends-form' => ['k' => 1, 2 => 'v']], 'd' => ['x-openregister-extends-form' => null]]]];
		$cases['fromSchema configuration scalar'] = ['fromSchema', [['x-openregister-extends-form' => 'x'], []]];
		return $cases;
	}//end cases()

	/**
	 * @param array<int, mixed> $arguments
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(string $method, array $arguments): void {
		$vocabulary = new PropertyVocabulary();
		$result = (new ExtendingFormDeclaration($vocabulary))->$method(...$arguments);
		if ($method === 'describe') {
			$this->assertSame(array_values(array_diff($vocabulary->keys(), $result['forwards'])), $result['narrows']);
			$this->assertSame(count($result['narrows']), $result['counts']['narrows']);
			$this->assertSame(count($vocabulary->keys()), $result['counts']['vocabulary']);
			unset($result['narrows'], $result['counts']['narrows'], $result['counts']['vocabulary']);
		}

		$this->assertGolden(['result' => $result]);
	}//end testMatchesGolden()

	public function testNarrowingIsTheVocabularyLessWhatIsForwarded(): void {
		$vocabulary = new PropertyVocabulary();
		$declarations = new ExtendingFormDeclaration($vocabulary);
		$annotation = ['forwards' => ['title', 'type'], 'definitions' => 'd'];
		$this->assertSame(array_values(array_diff($vocabulary->keys(), ['title', 'type'])), $declarations->narrowing($annotation));
		$this->assertSame($vocabulary->keys(), $declarations->narrowing('x'));
	}//end testNarrowingIsTheVocabularyLessWhatIsForwarded()

	/**
	 * @param array<string, mixed> $actual
	 */
	private function assertGolden(array $actual): void {
		$actual = json_decode(json_encode($actual), true);
		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
