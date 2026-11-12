<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Vocabulary\CodedValueGuard}.
 *
 * Pins the exact refusals `collectViolations()` returns, what `enforce()`
 * throws, and the scores `rolledUpScores()` computes, over one schema with
 * several coded properties (uri and notation storage, leaf-only, a score
 * target, an unreadable scheme) and objects that reach every refusal:
 * validity window, leaf-only, exclusive group within and across properties,
 * the last refusal winning on one property, and values that are skipped.
 * The instant is always passed explicitly. Written against the code before
 * its refactor and left unchanged by it.
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Service\Vocabulary\CodedValueWrite;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class CodedValueGuardGoldenTest extends TestCase {

	private CodedValueGuard $guard;

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private array $scheme = [
		'urn:afg' => ['uri' => 'urn:afg', 'prefLabel' => ['nl' => 'Afgehandeld'], 'notation' => 'AFG', 'weight' => 3],
		'urn:ing' => ['uri' => 'urn:ing', 'prefLabel' => ['nl' => 'Ingetrokken'], 'notation' => 'ING', 'validFrom' => '2019-01-01', 'validUntil' => '2025-12-31', 'weight' => '5'],
		'urn:new' => ['uri' => 'urn:new', 'prefLabel' => ['nl' => 'Nieuw'], 'notation' => 'NEW', 'validFrom' => '2027-01-01'],
		'urn:spoed' => ['uri' => 'urn:spoed', 'prefLabel' => ['nl' => 'Spoed'], 'notation' => 'SP', 'exclusiveGroup' => ' urgentie '],
		'urn:regulier' => ['uri' => 'urn:regulier', 'prefLabel' => ['nl' => 'Regulier'], 'notation' => 'RE', 'exclusiveGroup' => 'urgentie', 'weight' => 1.5],
		'urn:laag' => ['uri' => 'urn:laag', 'prefLabel' => ['en' => 'Low'], 'notation' => 'LA', 'exclusiveGroup' => '  '],
		'urn:verg' => ['uri' => 'urn:verg', 'prefLabel' => ['nl' => 'Vergunning'], 'notation' => 'VG', 'narrower' => ['urn:kap']],
		'urn:kap' => ['uri' => 'urn:kap', 'prefLabel' => ['nl' => 'Kap'], 'notation' => 'KAP', 'broader' => ['urn:verg']],
		'urn:nouri' => ['prefLabel' => ['nl' => 'Zonder uri'], 'notation' => 'NU'],
	];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$lifecycle = new ConceptLifecycle();
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptsOf')->willReturnCallback(
			fn (string $schemeUri): array => ($schemeUri === 'urn:s1' ? $this->scheme : [])
		);
		$concepts->method('resolve')->willReturnCallback(
			function (string $value, CodedPropertyDeclaration $declaration): ?array {
				$schemeUri = $declaration->scheme;
				$store = $declaration->store;
				if ($schemeUri !== 'urn:s1') {
					return null;
				}

				if ($store === 'notation') {
					foreach ($this->scheme as $concept) {
						if (($concept['notation'] ?? null) === $value) {
							return $concept;
						}
					}

					return null;
				}

				return ($this->scheme[$value] ?? null);
			}
		);

		$this->guard = new CodedValueGuard(new \OCA\OpenRegister\Service\Vocabulary\ConceptSchemeAccess($concepts, new ConceptHierarchy($lifecycle), $lifecycle), new CodedPropertyDeclarationFactory());
	}//end setUp()

	/**
	 * @return Schema
	 */
	private static function schema(): Schema {
		$coded = static fn (array $declaration): array => ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => $declaration];
		$schema = new Schema();
		$schema->setProperties(
			[
				'resultaat' => $coded(['scheme' => 'urn:s1']),
				'code' => $coded(['scheme' => 'urn:s1', 'store' => 'notation', 'leafOnly' => true]),
				'urgentie' => $coded(['scheme' => 'urn:s1']),
				'urgentie2' => $coded(['scheme' => 'urn:s1', 'score' => ['property' => 'punten']]),
				'score' => $coded(['scheme' => 'urn:s1', 'score' => ['property' => 'totaal']]),
				'outage' => $coded(['scheme' => 'urn:missing', 'score' => ['property' => 'nul']]),
				'plain' => ['type' => 'string'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function objects(): array {
		return [
			'empty' => [],
			'all valid' => ['resultaat' => 'urn:afg', 'code' => 'KAP', 'urgentie' => 'urn:spoed', 'score' => ['urn:afg', 'urn:laag'], 'plain' => 'x'],
			'retired and not yet valid' => ['resultaat' => ['urn:ing', 'urn:new'], 'urgentie' => 'urn:ing'],
			'not a leaf' => ['code' => ['VG', 'AFG'], 'resultaat' => 'urn:verg'],
			'group within one property' => ['urgentie' => ['urn:spoed', 'urn:regulier', 'urn:laag']],
			'group across properties' => ['urgentie' => 'urn:regulier', 'urgentie2' => ['urn:spoed']],
			'last refusal wins' => ['code' => ['VG', 'ING'], 'urgentie' => ['urn:ing', 'urn:spoed', 'urn:regulier']],
			'skipped values' => ['resultaat' => ['', 'urn:unknown', 5, null, ['urn:ing']], 'code' => '', 'urgentie' => 7, 'outage' => 'urn:ing'],
			'missing uri falls back to value' => ['code' => 'NU', 'resultaat' => 'urn:nouri'],
			'scored' => ['score' => ['urn:afg', 'urn:ing', 'urn:regulier', 'urn:unknown', ''], 'urgentie2' => 'urn:regulier', 'outage' => ['urn:afg']],
		];
	}//end objects()

	/**
	 * @return void
	 */
	public function testCollectViolationsAndEnforce(): void {
		$at = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
		$actual = [];
		foreach (self::objects() as $name => $object) {
			$violations = $this->guard->collectViolations(new \OCA\OpenRegister\Service\Vocabulary\CodedValueWrite($object, self::schema(), $at));
			try {
				$this->guard->enforce(new CodedValueWrite(object: $object, schema: self::schema(), at: $at));
				$thrown = null;
			} catch (CodedValueException $e) {
				$thrown = [$e->getMessage(), $e->getErrors()];
			}

			$actual[$name] = [$violations, $thrown];
		}

		$actual['schema without coded properties'] = $this->guard->collectViolations(new \OCA\OpenRegister\Service\Vocabulary\CodedValueWrite(['resultaat' => 'urn:ing'], new Schema(), $at));

		$this->assertSame(self::expectedViolations(), $actual);
	}//end testCollectViolationsAndEnforce()

	/**
	 * @return void
	 */
	public function testRolledUpScores(): void {
		$actual = [];
		foreach (self::objects() as $name => $object) {
			$actual[$name] = $this->guard->rolledUpScores($object, self::schema());
		}

		$actual['schema without coded properties'] = $this->guard->rolledUpScores(['score' => 'urn:afg'], new Schema());

		$this->assertSame(self::expectedScores(), $actual);
	}//end testRolledUpScores()

	/**
	 * @return array<string, mixed>
	 */
	private static function expectedViolations(): array {
		return [
			'empty' => [
				[],
				NULL,
			],
			'all valid' => [
				[],
				NULL,
			],
			'retired and not yet valid' => [
				[
					'resultaat' => 'The value "Nieuw" on "resultaat" is outside its validity window (valid from 2027-01-01) and can no longer be chosen.',
					'urgentie' => 'The value "Ingetrokken" on "urgentie" is outside its validity window (valid from 2019-01-01 until 2025-12-31) and can no longer be chosen.',
				],
				[
					'The value "Nieuw" on "resultaat" is outside its validity window (valid from 2027-01-01) and can no longer be chosen. The value "Ingetrokken" on "urgentie" is outside its validity window (valid from 2019-01-01 until 2025-12-31) and can no longer be chosen.',
					[
						'resultaat' => 'The value "Nieuw" on "resultaat" is outside its validity window (valid from 2027-01-01) and can no longer be chosen.',
						'urgentie' => 'The value "Ingetrokken" on "urgentie" is outside its validity window (valid from 2019-01-01 until 2025-12-31) and can no longer be chosen.',
					],
				],
			],
			'not a leaf' => [
				[
					'code' => 'The value "Vergunning" on "code" has narrower values under it, and this field accepts only the most specific value.',
				],
				[
					'The value "Vergunning" on "code" has narrower values under it, and this field accepts only the most specific value.',
					[
						'code' => 'The value "Vergunning" on "code" has narrower values under it, and this field accepts only the most specific value.',
					],
				],
			],
			'group within one property' => [
				[
					'urgentie' => 'The values "Spoed" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
				],
				[
					'The values "Spoed" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
					[
						'urgentie' => 'The values "Spoed" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
					],
				],
			],
			'group across properties' => [
				[
					'urgentie2' => 'The values "Regulier" and "Spoed" both belong to the group "urgentie", of which only one may be held.',
				],
				[
					'The values "Regulier" and "Spoed" both belong to the group "urgentie", of which only one may be held.',
					[
						'urgentie2' => 'The values "Regulier" and "Spoed" both belong to the group "urgentie", of which only one may be held.',
					],
				],
			],
			'last refusal wins' => [
				[
					'code' => 'The value "Ingetrokken" on "code" is outside its validity window (valid from 2019-01-01 until 2025-12-31) and can no longer be chosen.',
					'urgentie' => 'The values "Spoed" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
				],
				[
					'The value "Ingetrokken" on "code" is outside its validity window (valid from 2019-01-01 until 2025-12-31) and can no longer be chosen. The values "Spoed" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
					[
						'code' => 'The value "Ingetrokken" on "code" is outside its validity window (valid from 2019-01-01 until 2025-12-31) and can no longer be chosen.',
						'urgentie' => 'The values "Spoed" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
					],
				],
			],
			'skipped values' => [
				[],
				NULL,
			],
			'missing uri falls back to value' => [
				[],
				NULL,
			],
			'scored' => [
				[
					'score' => 'The values "Regulier" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
				],
				[
					'The values "Regulier" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
					[
						'score' => 'The values "Regulier" and "Regulier" both belong to the group "urgentie", of which only one may be held.',
					],
				],
			],
			'schema without coded properties' => [],
		];
	}//end expectedViolations()

	/**
	 * @return array<string, mixed>
	 */
	private static function expectedScores(): array {
		return [
			'empty' => [],
			'all valid' => [
				'totaal' => 3.0,
			],
			'retired and not yet valid' => [],
			'not a leaf' => [],
			'group within one property' => [],
			'group across properties' => [
				'punten' => 0.0,
			],
			'last refusal wins' => [],
			'skipped values' => [
				'nul' => 0.0,
			],
			'missing uri falls back to value' => [],
			'scored' => [
				'punten' => 1.5,
				'totaal' => 9.5,
				'nul' => 0.0,
			],
			'schema without coded properties' => [],
		];
	}//end expectedScores()
}//end class
