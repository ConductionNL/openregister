<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder}.
 *
 * Pins the exact flat option list and option tree for a spread of
 * declarations (uri and notation storage, a branch that exists and one that
 * does not, leaf-only, deprecated allowed, a depth bound, a context-bound
 * subset, an empty scheme) over one scheme with retired, deprecated,
 * notation-less and context-scoped concepts. The instant is always passed
 * explicitly, so nothing depends on today's date. Written against the code
 * before its refactor and left unchanged by it.
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
use OCA\OpenRegister\Service\Vocabulary\CodedNarrowing;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedValueRules;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class CodedOptionsBuilderGoldenTest extends TestCase {

	private CodedOptionsBuilder $builder;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$scheme = [
			'urn:top' => ['uri' => 'urn:top', 'prefLabel' => ['nl' => 'Top', 'en' => 'Top EN'], 'notation' => 'T', 'weight' => '2.5'],
			'urn:mid' => ['uri' => 'urn:mid', 'prefLabel' => ['nl' => 'Midden'], 'notation' => ' M ', 'broader' => 'urn:top', 'weight' => 3, 'fields' => ['x' => 1]],
			'urn:leaf1' => ['uri' => 'urn:leaf1', 'prefLabel' => ['nl' => 'Blad 1'], 'notation' => 'L1', 'broader' => ['urn:mid'], 'contexts' => ['bezwaar']],
			'urn:leaf2' => ['uri' => 'urn:leaf2', 'prefLabel' => ['nl' => 'Blad 2'], 'broader' => [['uri' => 'urn:mid']], 'contexts' => 'beroep'],
			'urn:old' => ['uri' => 'urn:old', 'prefLabel' => ['nl' => 'Oud'], 'notation' => 'O', 'broader' => 'urn:top', 'validUntil' => '2020-01-01T00:00:00+00:00'],
			'urn:dep' => ['uri' => 'urn:dep', 'prefLabel' => ['nl' => 'Vervallen'], 'notation' => 'D', 'deprecated' => true, 'broader' => []],
			'urn:solo' => ['uri' => 'urn:solo', 'prefLabel' => ['de' => 'Allein'], 'notation' => '', 'broader' => '', 'weight' => 'heavy'],
		];

		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptsOf')->willReturnCallback(
			static fn (string $schemeUri): array => ($schemeUri === 'urn:scheme' ? $scheme : [])
		);

		$lifecycle = new ConceptLifecycle();
		$this->builder = new CodedOptionsBuilder($concepts, new ConceptHierarchy($lifecycle), $lifecycle);
	}//end setUp()

	/**
	 * @return array<string, CodedPropertyDeclaration>
	 */
	private static function declarations(): array {
		return [
			'plain' => new CodedPropertyDeclaration('urn:scheme'),
			'notation' => new CodedPropertyDeclaration('urn:scheme', new CodedValueRules('notation')),
			'deprecated allowed' => new CodedPropertyDeclaration('urn:scheme', new CodedValueRules('uri', true)),
			'branch' => new CodedPropertyDeclaration('urn:scheme', new CodedValueRules(), new CodedNarrowing('urn:mid')),
			'missing branch' => new CodedPropertyDeclaration('urn:scheme', new CodedValueRules(), new CodedNarrowing('urn:nope')),
			'leaf only' => new CodedPropertyDeclaration('urn:scheme', new CodedValueRules('notation', false, true), new CodedNarrowing('urn:top')),
			'depth 1' => new CodedPropertyDeclaration('urn:scheme', new CodedValueRules(), new CodedNarrowing('urn:top', 1)),
			'context bound' => new CodedPropertyDeclaration('urn:scheme', new CodedValueRules(), new CodedNarrowing(null, null, 'zaaktype')),
			'empty scheme' => new CodedPropertyDeclaration('urn:empty'),
		];
	}//end declarations()

	/**
	 * @return void
	 */
	public function testOptions(): void {
		$at = new DateTimeImmutable('2025-06-01T00:00:00+00:00');
		$actual = [];
		foreach (self::declarations() as $name => $declaration) {
			$actual[$name] = $this->builder->options($declaration, 'en', 'bezwaar', $at);
		}

		$actual['context empty'] = $this->builder->options(self::declarations()['context bound'], 'nl', '', $at);
		$actual['context beroep'] = $this->builder->options(self::declarations()['context bound'], 'nl', 'beroep', $at);
		$actual['before retirement'] = $this->builder->options(self::declarations()['plain'], 'nl', null, new DateTimeImmutable('2019-06-01T00:00:00+00:00'));

		$this->assertSame(self::EXPECTED_OPTIONS, json_encode($actual));
	}//end testOptions()

	/**
	 * @return void
	 */
	public function testTree(): void {
		$at = new DateTimeImmutable('2025-06-01T00:00:00+00:00');
		$actual = [];
		foreach (self::declarations() as $name => $declaration) {
			$actual[$name] = $this->builder->tree($declaration, 'en', $at);
		}

		$this->assertSame(self::EXPECTED_TREE, json_encode($actual));
	}//end testTree()

	private const EXPECTED_OPTIONS =
		'{"plain":[{"value":"urn:top","uri":"urn:top","label":"Top EN","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:leaf2","uri":"urn:leaf2","label":"Blad 2","notation":null,"weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:solo","uri":"urn:solo","label":"Allein","notation":"","weight":0,"leaf":true,"fields":null}],"notation":[{"value":"T","uri":"urn:top","label":"Top EN","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"M","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"L1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null}],"deprecated allowed":[{"value":"urn:top","uri":"urn:top","label":"Top EN","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:leaf2","uri":"urn:leaf2","label":"Blad 2","notation":null,"weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:dep","uri":"urn:dep","label":"Vervallen","notation":"D","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:solo","uri":"urn:solo","label":"Allein","notation":"","weight":0,"leaf":true,"fields":null}],"branch":[{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:leaf2","uri":"urn:leaf2","label":"Blad 2","notation":null,"weight":0,"leaf":true,"fields":null}],"missing branch":[{"value":"urn:top","uri":"urn:top","label":"Top EN","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:leaf2","uri":"urn:leaf2","label":"Blad 2","notation":null,"weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:solo","uri":"urn:solo","label":"Allein","notation":"","weight":0,"leaf":true,"fields":null}],"leaf only":[{"value":"L1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null}],"depth 1":[{"value":"urn:top","uri":"urn:top","label":"Top EN","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}}],"context bound":[{"value":"urn:top","uri":"urn:top","label":"Top EN","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:solo","uri":"urn:solo","label":"Allein","notation":"","weight":0,"leaf":true,"fields":null}],"empty scheme":[],"context empty":[{"value":"urn:top","uri":"urn:top","label":"Top","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:leaf2","uri":"urn:leaf2","label":"Blad 2","notation":null,"weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:solo","uri":"urn:solo","label":"Allein","notation":"","weight":0,"leaf":true,"fields":null}],"context beroep":[{"value":"urn:top","uri":"urn:top","label":"Top","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf2","uri":"urn:leaf2","label":"Blad 2","notation":null,"weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:solo","uri":"urn:solo","label":"Allein","notation":"","weight":0,"leaf":true,"fields":null}],"before retirement":[{"value":"urn:top","uri":"urn:top","label":"Top","notation":"T","weight":2.5,"leaf":false,"fields":null},'
		.'{"value":"urn:mid","uri":"urn:mid","label":"Midden","notation":" M ","weight":3,"leaf":false,"fields":{"x":1}},'
		.'{"value":"urn:leaf1","uri":"urn:leaf1","label":"Blad 1","notation":"L1","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:leaf2","uri":"urn:leaf2","label":"Blad 2","notation":null,"weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:old","uri":"urn:old","label":"Oud","notation":"O","weight":0,"leaf":true,"fields":null},'
		.'{"value":"urn:solo","uri":"urn:solo","label":"Allein","notation":"","weight":0,"leaf":true,"fields":null}]}';

	private const EXPECTED_TREE =
		'{"plain":[{"value":"urn:top","label":"Top EN","notation":"T","offerable":true,"leaf":false,"children":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[{"value":"urn:leaf1","label":"Blad 1","notation":"L1","offerable":true,"leaf":true,"children":[]},'
		.'{"value":"urn:leaf2","label":"Blad 2","notation":null,"offerable":true,"leaf":true,"children":[]}]},'
		.'{"value":"urn:old","label":"Oud","notation":"O","offerable":false,"leaf":true,"children":[]}]},'
		.'{"value":"urn:dep","label":"Vervallen","notation":"D","offerable":false,"leaf":true,"children":[]},'
		.'{"value":"urn:solo","label":"Allein","notation":"","offerable":true,"leaf":true,"children":[]}],"notation":[{"value":"urn:top","label":"Top EN","notation":"T","offerable":true,"leaf":false,"children":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[{"value":"urn:leaf1","label":"Blad 1","notation":"L1","offerable":true,"leaf":true,"children":[]},'
		.'{"value":"urn:leaf2","label":"Blad 2","notation":null,"offerable":true,"leaf":true,"children":[]}]},'
		.'{"value":"urn:old","label":"Oud","notation":"O","offerable":false,"leaf":true,"children":[]}]},'
		.'{"value":"urn:dep","label":"Vervallen","notation":"D","offerable":false,"leaf":true,"children":[]},'
		.'{"value":"urn:solo","label":"Allein","notation":"","offerable":true,"leaf":true,"children":[]}],"deprecated allowed":[{"value":"urn:top","label":"Top EN","notation":"T","offerable":true,"leaf":false,"children":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[{"value":"urn:leaf1","label":"Blad 1","notation":"L1","offerable":true,"leaf":true,"children":[]},'
		.'{"value":"urn:leaf2","label":"Blad 2","notation":null,"offerable":true,"leaf":true,"children":[]}]},'
		.'{"value":"urn:old","label":"Oud","notation":"O","offerable":false,"leaf":true,"children":[]}]},'
		.'{"value":"urn:dep","label":"Vervallen","notation":"D","offerable":false,"leaf":true,"children":[]},'
		.'{"value":"urn:solo","label":"Allein","notation":"","offerable":true,"leaf":true,"children":[]}],"branch":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[{"value":"urn:leaf1","label":"Blad 1","notation":"L1","offerable":true,"leaf":true,"children":[]},'
		.'{"value":"urn:leaf2","label":"Blad 2","notation":null,"offerable":true,"leaf":true,"children":[]}]}],"missing branch":[{"value":"urn:top","label":"Top EN","notation":"T","offerable":true,"leaf":false,"children":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[{"value":"urn:leaf1","label":"Blad 1","notation":"L1","offerable":true,"leaf":true,"children":[]},'
		.'{"value":"urn:leaf2","label":"Blad 2","notation":null,"offerable":true,"leaf":true,"children":[]}]},'
		.'{"value":"urn:old","label":"Oud","notation":"O","offerable":false,"leaf":true,"children":[]}]},'
		.'{"value":"urn:dep","label":"Vervallen","notation":"D","offerable":false,"leaf":true,"children":[]},'
		.'{"value":"urn:solo","label":"Allein","notation":"","offerable":true,"leaf":true,"children":[]}],"leaf only":[{"value":"urn:top","label":"Top EN","notation":"T","offerable":true,"leaf":false,"children":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[{"value":"urn:leaf1","label":"Blad 1","notation":"L1","offerable":true,"leaf":true,"children":[]},'
		.'{"value":"urn:leaf2","label":"Blad 2","notation":null,"offerable":true,"leaf":true,"children":[]}]},'
		.'{"value":"urn:old","label":"Oud","notation":"O","offerable":false,"leaf":true,"children":[]}]}],"depth 1":[{"value":"urn:top","label":"Top EN","notation":"T","offerable":true,"leaf":false,"children":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[]},'
		.'{"value":"urn:old","label":"Oud","notation":"O","offerable":false,"leaf":true,"children":[]}]}],"context bound":[{"value":"urn:top","label":"Top EN","notation":"T","offerable":true,"leaf":false,"children":[{"value":"urn:mid","label":"Midden","notation":" M ","offerable":true,"leaf":false,"children":[{"value":"urn:leaf1","label":"Blad 1","notation":"L1","offerable":true,"leaf":true,"children":[]},'
		.'{"value":"urn:leaf2","label":"Blad 2","notation":null,"offerable":true,"leaf":true,"children":[]}]},'
		.'{"value":"urn:old","label":"Oud","notation":"O","offerable":false,"leaf":true,"children":[]}]},'
		.'{"value":"urn:dep","label":"Vervallen","notation":"D","offerable":false,"leaf":true,"children":[]},'
		.'{"value":"urn:solo","label":"Allein","notation":"","offerable":true,"leaf":true,"children":[]}],"empty scheme":[]}';
}//end class
