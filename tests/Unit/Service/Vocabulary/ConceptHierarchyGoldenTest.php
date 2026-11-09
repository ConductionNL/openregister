<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy}.
 *
 * Pins the exact output of every public method over one scheme that exercises
 * each relation dialect (bare string, list of strings, `{uri|id|uuid|@id}`
 * maps, blanks, dangling targets), a cycle, a one-sided relation and every
 * label fallback. Written against the code before its refactor and left
 * unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
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
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptReading;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class ConceptHierarchyGoldenTest extends TestCase {

	private ConceptHierarchy $hierarchy;

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private array $scheme;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->hierarchy = new ConceptHierarchy(new ConceptLifecycle());

		$this->scheme = [
			'urn:root' => [
				'uri' => 'urn:root',
				'prefLabel' => ['nl' => 'Wortel', 'en' => 'Root', 'en-GB' => ' '],
				'narrower' => ['urn:a', ['uri' => 'urn:b'], ['id' => 'urn:c'], '', 'urn:missing', 42],
			],
			'urn:a' => [
				'uri' => 'urn:a',
				'prefLabel' => ['de' => 'Ah', 'fr' => ''],
				'narrower' => 'urn:a1',
			],
			'urn:a1' => [
				'uri' => 'urn:a1',
				'notation' => 'A-1',
				'narrower' => [['uuid' => 'urn:a2']],
				'deprecated' => true,
			],
			'urn:a2' => [
				'uri' => 'urn:a2',
				'notation' => '  ',
				'narrower' => [['@id' => 'urn:a3'], ['uri' => '', 'id' => 'urn:root']],
			],
			'urn:a3' => [
				'uri' => 'urn:a3',
				'prefLabel' => 'not-a-map',
				'broader' => ['urn:a2'],
			],
			'urn:b' => [
				'uri' => 'urn:b',
				'prefLabel' => ['nl' => 'Bee'],
				'validUntil' => '2020-01-01T00:00:00+00:00',
			],
			'urn:c' => [
				'prefLabel' => [],
			],
			'urn:d' => [
				'uri' => 'urn:d',
				'prefLabel' => ['en' => 'Dee'],
				'broader' => 'urn:root',
			],
			'urn:e' => [
				'uri' => 'urn:e',
				'broader' => [['uri' => 'urn:d'], ['id' => 'urn:e']],
			],
			'urn:solo' => [
				'uri' => 'urn:solo',
			],
		];
	}//end setUp()

	/**
	 * @return void
	 */
	public function testBranchUrisAtEveryDepth(): void {
		$cases = [];
		foreach ([null, -3, 0, 1, 2, 3, 50] as $depth) {
			$cases[] = $this->hierarchy->branchUris('urn:root', $this->scheme, $depth);
		}

		$cases[] = $this->hierarchy->branchUris('urn:a', $this->scheme);
		$cases[] = $this->hierarchy->branchUris('urn:solo', $this->scheme);
		$cases[] = $this->hierarchy->branchUris('urn:unknown', $this->scheme);

		$this->assertSame(
			'[["urn:root","urn:a","urn:b","urn:c","urn:d","urn:a1","urn:e","urn:a2","urn:a3"],'
			.'["urn:root"],["urn:root"],["urn:root","urn:a","urn:b","urn:c","urn:d"],'
			.'["urn:root","urn:a","urn:b","urn:c","urn:d","urn:a1","urn:e"],'
			.'["urn:root","urn:a","urn:b","urn:c","urn:d","urn:a1","urn:e","urn:a2"],'
			.'["urn:root","urn:a","urn:b","urn:c","urn:d","urn:a1","urn:e","urn:a2","urn:a3"],'
			.'["urn:a","urn:a1","urn:a2","urn:a3","urn:root","urn:b","urn:c","urn:d","urn:e"],'
			.'["urn:solo"],["urn:unknown"]]',
			json_encode($cases)
		);
	}//end testBranchUrisAtEveryDepth()

	/**
	 * @return void
	 */
	public function testIsLeaf(): void {
		$leaves = [];
		foreach (array_merge(array_keys($this->scheme), ['urn:unknown']) as $uri) {
			$leaves[$uri] = $this->hierarchy->isLeaf((string) $uri, $this->scheme);
		}

		$this->assertSame(
			'{"urn:root":false,"urn:a":false,"urn:a1":false,"urn:a2":false,"urn:a3":true,"urn:b":true,'
			.'"urn:c":true,"urn:d":false,"urn:e":true,"urn:solo":true,"urn:unknown":true}',
			json_encode($leaves)
		);
	}//end testIsLeaf()

	/**
	 * @return void
	 */
	public function testLabelOfEveryFallback(): void {
		$labels = [];
		foreach (['en-GB', 'en', 'de-AT', 'fr', ''] as $language) {
			foreach ($this->scheme as $uri => $concept) {
				$labels[] = $language.'|'.$uri.'='.$this->hierarchy->labelOf($concept, $language);
			}
		}

		$this->assertSame($this->expectedLabels(), $labels);
	}//end testLabelOfEveryFallback()

	/**
	 * @return void
	 */
	public function testTreeAtSeveralDepths(): void {
		$at = new DateTimeImmutable('2025-06-01T00:00:00+00:00');

		$this->assertSame(
			$this->expectedFullTree(),
			json_encode($this->hierarchy->tree('urn:root', $this->scheme, new ConceptReading('en', $at)))
		);
		$this->assertSame(
			'{"value":"urn:root","label":"Root","notation":null,"offerable":true,"leaf":false,"children":[]}',
			json_encode($this->hierarchy->tree('urn:root', $this->scheme, new ConceptReading('en', $at), 0))
		);
		$this->assertSame(
			'{"value":"urn:a2","label":"urn:a2","notation":"  ","offerable":true,"leaf":false,"children":['
			.'{"value":"urn:a3","label":"urn:a3","notation":null,"offerable":true,"leaf":true,"children":[]},'
			.'{"value":"urn:root","label":"Wortel","notation":null,"offerable":true,"leaf":false,"children":[]}]}',
			json_encode($this->hierarchy->tree('urn:a2', $this->scheme, new ConceptReading('nl', $at), 1))
		);
	}//end testTreeAtSeveralDepths()

	/**
	 * @return array<int,string>
	 */
	private function expectedLabels(): array {
		return [
			'en-GB|urn:root=Root',
			'en-GB|urn:a=Ah',
			'en-GB|urn:a1=A-1',
			'en-GB|urn:a2=urn:a2',
			'en-GB|urn:a3=urn:a3',
			'en-GB|urn:b=Bee',
			'en-GB|urn:c=',
			'en-GB|urn:d=Dee',
			'en-GB|urn:e=urn:e',
			'en-GB|urn:solo=urn:solo',
			'en|urn:root=Root',
			'en|urn:a=Ah',
			'en|urn:a1=A-1',
			'en|urn:a2=urn:a2',
			'en|urn:a3=urn:a3',
			'en|urn:b=Bee',
			'en|urn:c=',
			'en|urn:d=Dee',
			'en|urn:e=urn:e',
			'en|urn:solo=urn:solo',
			'de-AT|urn:root=Wortel',
			'de-AT|urn:a=Ah',
			'de-AT|urn:a1=A-1',
			'de-AT|urn:a2=urn:a2',
			'de-AT|urn:a3=urn:a3',
			'de-AT|urn:b=Bee',
			'de-AT|urn:c=',
			'de-AT|urn:d=Dee',
			'de-AT|urn:e=urn:e',
			'de-AT|urn:solo=urn:solo',
			'fr|urn:root=Wortel',
			'fr|urn:a=Ah',
			'fr|urn:a1=A-1',
			'fr|urn:a2=urn:a2',
			'fr|urn:a3=urn:a3',
			'fr|urn:b=Bee',
			'fr|urn:c=',
			'fr|urn:d=Dee',
			'fr|urn:e=urn:e',
			'fr|urn:solo=urn:solo',
			'|urn:root=Wortel',
			'|urn:a=Ah',
			'|urn:a1=A-1',
			'|urn:a2=urn:a2',
			'|urn:a3=urn:a3',
			'|urn:b=Bee',
			'|urn:c=',
			'|urn:d=Dee',
			'|urn:e=urn:e',
			'|urn:solo=urn:solo',
		];
	}//end expectedLabels()

	/**
	 * @return string
	 */
	private function expectedFullTree(): string {
		return '{"value":"urn:root","label":"Root","notation":null,"offerable":true,"leaf":false,"children":['
			.'{"value":"urn:a","label":"Ah","notation":null,"offerable":true,"leaf":false,"children":['
			.'{"value":"urn:a1","label":"A-1","notation":"A-1","offerable":false,"leaf":false,"children":['
			.'{"value":"urn:a2","label":"urn:a2","notation":"  ","offerable":true,"leaf":false,"children":['
			.'{"value":"urn:a3","label":"urn:a3","notation":null,"offerable":true,"leaf":true,"children":[]}]}]}]},'
			.'{"value":"urn:b","label":"Bee","notation":null,"offerable":false,"leaf":true,"children":[]},'
			.'{"value":"urn:c","label":"","notation":null,"offerable":true,"leaf":true,"children":[]},'
			.'{"value":"urn:d","label":"Dee","notation":null,"offerable":true,"leaf":false,"children":['
			.'{"value":"urn:e","label":"urn:e","notation":null,"offerable":true,"leaf":true,"children":[]}]}]}';
	}//end expectedFullTree()
}//end class
