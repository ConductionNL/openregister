<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\CodedValueGuard}
 * and {@see \OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder}.
 *
 * These two classes carry the change's central asymmetry between them, so
 * they are tested together against ONE scheme: the guard refuses a new write
 * of a retired value, the options builder stops offering it, and neither
 * touches the record that already holds it.
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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CodedValueGuardTest extends TestCase {

	private const SCHEME = 'https://example.org/resultaattypen';

	private ConceptRepository&MockObject $concepts;

	private CodedValueGuard $guard;

	private CodedOptionsBuilder $options;

	/**
	 * The scheme under test.
	 *
	 * `afgehandeld` is live, `ingetrokken` was retired at the end of 2025,
	 * `spoed` and `regulier` are one exclusive group, and `vergunning` has a
	 * narrower concept so it is not a leaf.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $scheme;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$lifecycle = new ConceptLifecycle();
		$hierarchy = new ConceptHierarchy(lifecycle: $lifecycle);

		$this->scheme = [
			'urn:afgehandeld' => [
				'uri' => 'urn:afgehandeld',
				'prefLabel' => ['nl' => 'Afgehandeld'],
				'notation' => 'AFG',
				'weight' => 3,
			],
			'urn:ingetrokken' => [
				'uri' => 'urn:ingetrokken',
				'prefLabel' => ['nl' => 'Ingetrokken'],
				'notation' => 'ING',
				'validFrom' => '2019-01-01',
				'validUntil' => '2025-12-31',
				'weight' => 5,
			],
			'urn:spoed' => [
				'uri' => 'urn:spoed',
				'prefLabel' => ['nl' => 'Spoed'],
				'exclusiveGroup' => 'urgentie',
			],
			'urn:regulier' => [
				'uri' => 'urn:regulier',
				'prefLabel' => ['nl' => 'Regulier'],
				'exclusiveGroup' => 'urgentie',
			],
			'urn:vergunning' => [
				'uri' => 'urn:vergunning',
				'prefLabel' => ['nl' => 'Vergunning'],
				'narrower' => ['urn:kap'],
			],
			'urn:kap' => [
				'uri' => 'urn:kap',
				'prefLabel' => ['nl' => 'Kapvergunning'],
				'broader' => ['urn:vergunning'],
				'contexts' => ['bezwaar'],
			],
		];

		$this->concepts = $this->createMock(ConceptRepository::class);
		$this->concepts->method('conceptsOf')->willReturn($this->scheme);
		$this->concepts->method('resolve')->willReturnCallback(
			function (string $value, string $schemeUri, string $store = 'uri'): ?array {
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

		$this->guard = new CodedValueGuard(
			concepts: $this->concepts,
			lifecycle: $lifecycle,
			hierarchy: $hierarchy
		);
		$this->options = new CodedOptionsBuilder(
			concepts: $this->concepts,
			hierarchy: $hierarchy,
			lifecycle: $lifecycle
		);
	}//end setUp()

	/**
	 * Build a schema carrying one coded property.
	 *
	 * @param array<string,mixed> $declaration The x-openregister-concepts block.
	 * @param string $property The property name.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $declaration, string $property = 'resultaat'): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				$property => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => array_merge(['scheme' => self::SCHEME], $declaration),
				],
			]
		);

		return $schema;
	}//end schemaWith()

	/**
	 * A retired value cannot be written today, and the refusal names the
	 * concept and its window.
	 *
	 * @return void
	 */
	public function testARetiredValueCannotBeWrittenToday(): void {
		$schema = $this->schemaWith(declaration: []);

		try {
			$this->guard->enforce(
				object: ['resultaat' => 'urn:ingetrokken'],
				schema: $schema,
				at: new DateTimeImmutable('2026-09-14')
			);
			$this->fail('A write of a retired concept must be refused.');
		} catch (CodedValueException $refused) {
			$this->assertSame(422, $refused->getCode());
			$this->assertStringContainsString('Ingetrokken', $refused->getMessage());
			$this->assertStringContainsString('valid from 2019-01-01 until 2025-12-31', $refused->getMessage());
			$this->assertArrayHasKey('resultaat', $refused->getErrors());
		}
	}//end testARetiredValueCannotBeWrittenToday()

	/**
	 * The same value written INSIDE its window is accepted, which is what
	 * makes the refusal about the window rather than about the value.
	 *
	 * @return void
	 */
	public function testTheSameValueIsAcceptedInsideItsWindow(): void {
		$this->guard->enforce(
			object: ['resultaat' => 'urn:ingetrokken'],
			schema: $this->schemaWith(declaration: []),
			at: new DateTimeImmutable('2020-06-01')
		);

		$this->addToAssertionCount(1);
	}//end testTheSameValueIsAcceptedInsideItsWindow()

	/**
	 * A retired value is absent from the options while the record holding it
	 * is untouched. This is the pair the whole design rests on.
	 *
	 * @return void
	 */
	public function testARetiredValueIsAbsentFromTheOptionsAndStillResolves(): void {
		$declaration = CodedPropertyDeclaration::fromProperty(
			property: [CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME]]
		);

		$offered = array_column(
			$this->options->options(
				declaration: $declaration,
				language: 'nl',
				context: null,
				at: new DateTimeImmutable('2026-09-14')
			),
			'value'
		);

		$this->assertNotContains('urn:ingetrokken', $offered, 'A retired value is not offered.');
		$this->assertContains('urn:afgehandeld', $offered);

		// ... and the value still resolves, with its label, for the record
		// that already holds it.
		$held = $this->concepts->resolve(value: 'urn:ingetrokken', schemeUri: self::SCHEME);
		$this->assertNotNull($held);
		$this->assertSame('Ingetrokken', $held['prefLabel']['nl']);
	}//end testARetiredValueIsAbsentFromTheOptionsAndStillResolves()

	/**
	 * Two concepts of one exclusive group are refused, naming the group and
	 * both values.
	 *
	 * @return void
	 */
	public function testTwoConceptsOfOneExclusiveGroupAreRefused(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'urgentie' => [
					'type' => 'array',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME],
				],
			]
		);

		try {
			$this->guard->enforce(
				object: ['urgentie' => ['urn:spoed', 'urn:regulier']],
				schema: $schema,
				at: new DateTimeImmutable('2026-09-14')
			);
			$this->fail('Two concepts of one exclusive group must be refused.');
		} catch (CodedValueException $refused) {
			$this->assertStringContainsString('urgentie', $refused->getMessage());
			$this->assertStringContainsString('Spoed', $refused->getMessage());
			$this->assertStringContainsString('Regulier', $refused->getMessage());
		}
	}//end testTwoConceptsOfOneExclusiveGroupAreRefused()

	/**
	 * A leaf-only property refuses a value that has narrower values under it.
	 *
	 * @return void
	 */
	public function testALeafOnlyPropertyRefusesABroaderValue(): void {
		$schema = $this->schemaWith(declaration: ['leafOnly' => true], property: 'categorie');

		try {
			$this->guard->enforce(
				object: ['categorie' => 'urn:vergunning'],
				schema: $schema,
				at: new DateTimeImmutable('2026-09-14')
			);
			$this->fail('A broader value must be refused under a leaf-only property.');
		} catch (CodedValueException $refused) {
			$this->assertStringContainsString('Vergunning', $refused->getMessage());
			$this->assertArrayHasKey('categorie', $refused->getErrors());
		}

		// The leaf under it is accepted.
		$this->guard->enforce(
			object: ['categorie' => 'urn:kap'],
			schema: $schema,
			at: new DateTimeImmutable('2026-09-14')
		);
		$this->addToAssertionCount(1);
	}//end testALeafOnlyPropertyRefusesABroaderValue()

	/**
	 * A scheme that cannot be read refuses nothing: a vocabulary outage must
	 * not become a write outage.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemeRefusesNothing(): void {
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptsOf')->willReturn([]);

		$lifecycle = new ConceptLifecycle();
		$guard = new CodedValueGuard(
			concepts: $concepts,
			lifecycle: $lifecycle,
			hierarchy: new ConceptHierarchy(lifecycle: $lifecycle)
		);

		$this->assertSame(
			[],
			$guard->collectViolations(
				object: ['resultaat' => 'urn:whatever'],
				schema: $this->schemaWith(declaration: [])
			)
		);
	}//end testAnUnreadableSchemeRefusesNothing()

	/**
	 * The weights of the concepts held roll up into the declared property.
	 *
	 * @return void
	 */
	public function testTheWeightsRollUpIntoTheDeclaredScoreProperty(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'criteria' => [
					'type' => 'array',
					CodedPropertyDeclaration::ANNOTATION => [
						'scheme' => self::SCHEME,
						'score' => ['property' => 'score'],
					],
				],
			]
		);

		$this->assertSame(
			['score' => 8.0],
			$this->guard->rolledUpScores(
				object: ['criteria' => ['urn:afgehandeld', 'urn:ingetrokken']],
				schema: $schema
			)
		);
	}//end testTheWeightsRollUpIntoTheDeclaredScoreProperty()

	/**
	 * One property serves two case types with different values.
	 *
	 * @return void
	 */
	public function testOnePropertyServesTwoCaseTypesWithDifferentValues(): void {
		$declaration = CodedPropertyDeclaration::fromProperty(
			property: [
				CodedPropertyDeclaration::ANNOTATION => [
					'scheme' => self::SCHEME,
					'contextProperty' => 'zaaktype',
				],
			]
		);

		$forBezwaar = array_column(
			$this->options->options(
				declaration: $declaration,
				language: 'nl',
				context: 'bezwaar',
				at: new DateTimeImmutable('2026-09-14')
			),
			'value'
		);
		$forMelding = array_column(
			$this->options->options(
				declaration: $declaration,
				language: 'nl',
				context: 'melding',
				at: new DateTimeImmutable('2026-09-14')
			),
			'value'
		);

		$this->assertContains('urn:kap', $forBezwaar, 'urn:kap declares the bezwaar context.');
		$this->assertNotContains('urn:kap', $forMelding, 'and therefore serves no other.');
		$this->assertContains(
			'urn:afgehandeld',
			$forMelding,
			'A concept declaring no context serves every context, so a scheme written before this change does not empty its picker.'
		);
	}//end testOnePropertyServesTwoCaseTypesWithDifferentValues()

	/**
	 * A property storing notations rather than uris is guarded identically.
	 *
	 * @return void
	 */
	public function testANotationStoringPropertyIsGuardedIdentically(): void {
		$schema = $this->schemaWith(declaration: ['store' => 'notation']);

		$violations = $this->guard->collectViolations(
			object: ['resultaat' => 'ING'],
			schema: $schema,
			at: new DateTimeImmutable('2026-09-14')
		);

		$this->assertArrayHasKey('resultaat', $violations);
		$this->assertStringContainsString('Ingetrokken', $violations['resultaat']);
	}//end testANotationStoringPropertyIsGuardedIdentically()
}//end class
