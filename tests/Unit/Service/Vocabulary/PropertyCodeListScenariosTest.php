<?php

/**
 * The spec scenarios of property-code-list-from-concept-scheme, at the service level (task 3.2).
 *
 * A coded property refuses a value outside its scheme naming the property and the scheme,
 * accepts a concept added to the scheme without a schema save, and offers the scheme's
 * concepts as options labelled in the asked language.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionScope;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Service\Vocabulary\CodedValueWrite;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\OpenRegister\Service\Vocabulary\ConceptSchemeAccess;
use PHPUnit\Framework\TestCase;

class PropertyCodeListScenariosTest extends TestCase {

	private const SCHEME = 'https://example.org/themes';

	/**
	 * The scheme as the repository holds it right now.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $scheme = [];

	protected function setUp(): void {
		for ($i = 1; $i <= 12; $i++) {
			$uri = self::SCHEME . '/' . $i;
			$this->scheme[$uri] = [
				'uri' => $uri,
				'notation' => 'T' . $i,
				'prefLabel' => ['nl' => 'Thema ' . $i, 'en' => 'Theme ' . $i],
			];
		}
	}

	/**
	 * The scheme services as one request gets them: a fresh repository read, no state from an earlier request.
	 *
	 * @return array{guard: CodedValueGuard, options: CodedOptionsBuilder}
	 */
	private function request(): array {
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptsOf')->willReturnCallback(
			fn (string $scheme): array => ($scheme === self::SCHEME ? $this->scheme : [])
		);
		$concepts->method('resolve')->willReturnCallback(
			fn (string $value, CodedPropertyDeclaration $declaration): ?array => ($this->scheme[$value] ?? null)
		);

		$lifecycle = new ConceptLifecycle();
		$access = new ConceptSchemeAccess(concepts: $concepts, hierarchy: new ConceptHierarchy(lifecycle: $lifecycle), lifecycle: $lifecycle);

		return [
			'guard' => new CodedValueGuard(scheme: $access, declarationFactory: new CodedPropertyDeclarationFactory()),
			'options' => new CodedOptionsBuilder(scheme: $access),
		];
	}

	/**
	 * A schema with one coded `theme` property on the scheme.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['theme' => ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME]]]);
		return $schema;
	}

	/**
	 * Save `theme` with a value.
	 *
	 * @param CodedValueGuard $guard The guard of this request.
	 * @param string $value The value.
	 *
	 * @return CodedValueException|null The refusal, or null when the save is accepted.
	 */
	private static function save(CodedValueGuard $guard, string $value): ?CodedValueException {
		try {
			$guard->enforce(new CodedValueWrite(object: ['theme' => $value], schema: self::schema(), at: new DateTimeImmutable('2026-10-01')));
		} catch (CodedValueException $refusal) {
			return $refusal;
		}

		return null;
	}

	public function testAValueOutsideTheSchemeIsRefusedNamingThePropertyAndTheScheme(): void {
		$refusal = self::save($this->request()['guard'], self::SCHEME . '/99');

		$this->assertNotNull($refusal, 'A value the scheme does not hold must be refused.');
		$this->assertSame(422, $refusal->getCode());
		$this->assertArrayHasKey('theme', $refusal->getErrors());
		$this->assertStringContainsString(self::SCHEME, $refusal->getMessage() . ' ' . json_encode($refusal->getErrors()));
		$this->assertNull(self::save($this->request()['guard'], self::SCHEME . '/3'));
	}

	public function testANewConceptIsAcceptedWithoutASchemaSave(): void {
		$new = self::SCHEME . '/13';
		$this->assertNotNull(self::save($this->request()['guard'], $new), 'Before it exists the concept is refused.');

		// A minute later the concept is added to the scheme; the schema is not touched.
		$this->scheme[$new] = ['uri' => $new, 'notation' => 'T13', 'prefLabel' => ['nl' => 'Thema 13']];

		$this->assertNull(self::save($this->request()['guard'], $new));
	}

	public function testADeprecatedConceptIsRefusedUnlessTheDeclarationAllowsIt(): void {
		$this->scheme[self::SCHEME . '/5']['deprecated'] = true;

		$refusal = self::save($this->request()['guard'], self::SCHEME . '/5');
		$this->assertNotNull($refusal);
		$this->assertStringContainsString('deprecated', $refusal->getErrors()['theme']);
		$this->assertStringContainsString(self::SCHEME, $refusal->getErrors()['theme']);

		$allowing = new Schema();
		$allowing->setProperties(['theme' => ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME, 'allowDeprecated' => true]]]);
		$this->request()['guard']->enforce(new CodedValueWrite(object: ['theme' => self::SCHEME . '/5'], schema: $allowing, at: new DateTimeImmutable('2026-10-01')));
		$this->addToAssertionCount(1);
	}

	public function testAnUnreadableSchemeStillRefusesNothing(): void {
		$this->scheme = [];

		$this->assertNull(self::save($this->request()['guard'], self::SCHEME . '/1'));
	}

	public function testAFormGetsEveryConceptAsAnOptionInTheAskedLanguage(): void {
		$declaration = (new CodedPropertyDeclarationFactory())->fromProperty(
			property: [CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME]]
		);
		$builder = $this->request()['options'];

		$dutch = $builder->options(declaration: $declaration, scope: new CodedOptionScope(language: 'nl', asOf: new DateTimeImmutable('2026-10-01')));
		$english = $builder->options(declaration: $declaration, scope: new CodedOptionScope(language: 'en', asOf: new DateTimeImmutable('2026-10-01')));

		$this->assertCount(12, $dutch);
		$this->assertSame(self::SCHEME . '/1', $dutch[0]['value']);
		$this->assertSame('T1', $dutch[0]['notation']);
		$this->assertSame(array_map(static fn (int $i): string => 'Thema ' . $i, range(1, 12)), array_column($dutch, 'label'));
		$this->assertSame(array_map(static fn (int $i): string => 'Theme ' . $i, range(1, 12)), array_column($english, 'label'));
	}
}
