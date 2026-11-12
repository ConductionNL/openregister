<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuard}.
 *
 * Pins the exact refusals `violations()` returns, and which scheme reference
 * it asks the repository about, for every way a concept can name its scheme
 * (bare uri, uuid, `{uri|id|uuid}` map, blank, unknown, a scheme without a
 * uri) and for a non-concept schema. Written against the code before its
 * refactor and left unchanged by it.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuard;
use OCA\OpenRegister\Service\VocabularyImportService;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class ConceptShapeGuardGoldenTest extends TestCase {

	/**
	 * @return void
	 */
	public function testViolations(): void {
		$asked = [];
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('schemeByReference')->willReturnCallback(
			static function (string $reference) use (&$asked): ?array {
				$asked[] = 'ref:'.$reference;
				return [
					'uuid-1' => ['uri' => ' https://voc.example/s1 '],
					'uuid-2' => ['uri' => '  '],
					'uuid-3' => ['title' => 'no uri'],
				][$reference] ?? null;
			}
		);
		$concepts->method('conceptShape')->willReturnCallback(
			static function (string $schemeUri) use (&$asked): array {
				$asked[] = 'shape:'.$schemeUri;
				if ($schemeUri === 'https://voc.example/empty') {
					return [];
				}

				return ['properties' => ['kleur' => ['type' => 'string'], 'maat' => ['type' => 'integer']], 'required' => ['kleur']];
			}
		);

		$guard = new ConceptShapeGuard($concepts, new ConceptLifecycle());
		$concept = new Schema();
		$concept->setSlug(VocabularyImportService::SCHEMA_CONCEPT);
		$other = new Schema();
		$other->setSlug('zaak');

		$bad = ['fields' => ['maat' => 'groot']];
		$objects = [
			'other schema' => [$other, ['inScheme' => 'https://voc.example/s1'] + $bad],
			'no scheme' => [$concept, $bad],
			'uri reference' => [$concept, ['uri' => 'urn:c1', 'inScheme' => '  https://voc.example/s1 '] + $bad],
			'uuid reference' => [$concept, ['inScheme' => 'uuid-1'] + $bad],
			'map uri' => [$concept, ['uri' => 'urn:c2', 'inScheme' => ['uri' => 'uuid-1', 'id' => 'x']] + $bad],
			'map id' => [$concept, ['inScheme' => ['id' => 'https://voc.example/s2']] + $bad],
			'map uuid' => [$concept, ['inScheme' => ['uuid' => 'uuid-1']] + $bad],
			'map empty' => [$concept, ['inScheme' => []] + $bad],
			'blank' => [$concept, ['inScheme' => '   '] + $bad],
			'number' => [$concept, ['inScheme' => 12] + $bad],
			'unknown uuid' => [$concept, ['inScheme' => 'uuid-9'] + $bad],
			'scheme blank uri' => [$concept, ['inScheme' => 'uuid-2'] + $bad],
			'scheme without uri' => [$concept, ['inScheme' => 'uuid-3'] + $bad],
			'empty shape' => [$concept, ['inScheme' => 'https://voc.example/empty'] + $bad],
			'valid' => [$concept, ['inScheme' => 'uuid-1', 'fields' => ['kleur' => 'rood', 'maat' => 3]]],
		];

		$actual = [];
		foreach ($objects as $name => [$schema, $object]) {
			$asked = [];
			$actual[$name] = [$guard->violations($object, $schema), $asked];
		}

		$this->assertSame(self::expected(), $actual);
	}//end testViolations()

	/**
	 * @return array<string, mixed>
	 */
	private static function expected(): array {
		return [
			'other schema' => [
				[],
				[],
			],
			'no scheme' => [
				[],
				[],
			],
			'uri reference' => [
				[
					'kleur' => 'The value "urn:c1" is missing the field "kleur", which its scheme requires of every value.',
					'maat' => 'The value "urn:c1" is missing the field "maat", which its scheme requires of every value.',
				],
				[
					'shape:https://voc.example/s1',
				],
			],
			'uuid reference' => [
				[
					'kleur' => 'The value "unnamed" is missing the field "kleur", which its scheme requires of every value.',
					'maat' => 'The value "unnamed" is missing the field "maat", which its scheme requires of every value.',
				],
				[
					'ref:uuid-1',
					'shape:https://voc.example/s1',
				],
			],
			'map uri' => [
				[
					'kleur' => 'The value "urn:c2" is missing the field "kleur", which its scheme requires of every value.',
					'maat' => 'The value "urn:c2" is missing the field "maat", which its scheme requires of every value.',
				],
				[
					'ref:uuid-1',
					'shape:https://voc.example/s1',
				],
			],
			'map id' => [
				[
					'kleur' => 'The value "unnamed" is missing the field "kleur", which its scheme requires of every value.',
					'maat' => 'The value "unnamed" is missing the field "maat", which its scheme requires of every value.',
				],
				[
					'shape:https://voc.example/s2',
				],
			],
			'map uuid' => [
				[
					'kleur' => 'The value "unnamed" is missing the field "kleur", which its scheme requires of every value.',
					'maat' => 'The value "unnamed" is missing the field "maat", which its scheme requires of every value.',
				],
				[
					'ref:uuid-1',
					'shape:https://voc.example/s1',
				],
			],
			'map empty' => [
				[],
				[],
			],
			'blank' => [
				[],
				[],
			],
			'number' => [
				[],
				[],
			],
			'unknown uuid' => [
				[],
				[
					'ref:uuid-9',
				],
			],
			'scheme blank uri' => [
				[],
				[
					'ref:uuid-2',
				],
			],
			'scheme without uri' => [
				[],
				[
					'ref:uuid-3',
				],
			],
			'empty shape' => [
				[],
				[
					'shape:https://voc.example/empty',
				],
			],
			'valid' => [
				[],
				[
					'ref:uuid-1',
					'shape:https://voc.example/s1',
				],
			],
		];
	}//end expected()
}//end class
