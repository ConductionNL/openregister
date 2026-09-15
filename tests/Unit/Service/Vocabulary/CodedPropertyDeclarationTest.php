<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration}
 * and {@see \OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuard}.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuard;
use PHPUnit\Framework\TestCase;

class CodedPropertyDeclarationTest extends TestCase {

	/**
	 * Every key of the declaration is read, in both the array and the object
	 * shape a schema can arrive in.
	 *
	 * @return void
	 */
	public function testTheWholeDeclarationIsRead(): void {
		$block = [
			'scheme' => 'https://example.org/s',
			'store' => 'notation',
			'allowDeprecated' => true,
			'branch' => 'urn:root',
			'leafOnly' => true,
			'maxDepth' => 2,
			'contextProperty' => 'zaaktype',
			'score' => ['property' => 'totaal'],
		];

		foreach ([$block, (object)$block] as $shape) {
			$declaration = CodedPropertyDeclaration::fromProperty(
				property: [CodedPropertyDeclaration::ANNOTATION => $shape]
			);

			$this->assertNotNull($declaration);
			$this->assertSame('https://example.org/s', $declaration->scheme);
			$this->assertSame('notation', $declaration->store);
			$this->assertTrue($declaration->allowDeprecated);
			$this->assertSame('urn:root', $declaration->branch);
			$this->assertTrue($declaration->leafOnly);
			$this->assertSame(2, $declaration->maxDepth);
			$this->assertSame('zaaktype', $declaration->contextProperty);
			$this->assertSame('totaal', $declaration->scoreProperty);
			$this->assertTrue($declaration->isContextBound());
		}
	}//end testTheWholeDeclarationIsRead()

	/**
	 * A declaration without a scheme is not a declaration, it is a typo. The
	 * property then behaves as an ordinary string rather than as a code list
	 * bound to nothing.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutASchemeIsNotADeclaration(): void {
		$this->assertNull(
			CodedPropertyDeclaration::fromProperty(
				property: [CodedPropertyDeclaration::ANNOTATION => ['branch' => 'urn:root']]
			)
		);
		$this->assertNull(CodedPropertyDeclaration::fromProperty(property: ['type' => 'string']));
		$this->assertNull(CodedPropertyDeclaration::fromProperty(property: 'string'));
	}//end testADeclarationWithoutASchemeIsNotADeclaration()

	/**
	 * An unknown storage form falls back to the uri rather than being taken
	 * literally, because a property storing "uir" stores nothing resolvable.
	 *
	 * @return void
	 */
	public function testAnUnknownStorageFormFallsBackToTheUri(): void {
		$declaration = CodedPropertyDeclaration::fromProperty(
			property: [CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'urn:s', 'store' => 'uir']]
		);

		$this->assertNotNull($declaration);
		$this->assertSame('uri', $declaration->store);
		$this->assertFalse($declaration->isContextBound());
	}//end testAnUnknownStorageFormFallsBackToTheUri()

	/**
	 * A concept declaring no context serves every context, so a scheme
	 * written before this change does not empty its picker under a
	 * context-bound property.
	 *
	 * @return void
	 */
	public function testAConceptDeclaringNoContextServesEveryContext(): void {
		$declaration = CodedPropertyDeclaration::fromProperty(
			property: [
				CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'urn:s', 'contextProperty' => 'zaaktype'],
			]
		);

		$this->assertNotNull($declaration);
		$this->assertTrue($declaration->matchesContext(concept: ['uri' => 'urn:a'], context: 'bezwaar'));
		$this->assertTrue(
			$declaration->matchesContext(concept: ['contexts' => ['bezwaar']], context: 'bezwaar')
		);
		$this->assertFalse(
			$declaration->matchesContext(concept: ['contexts' => ['bezwaar']], context: 'melding')
		);
	}//end testAConceptDeclaringNoContextServesEveryContext()

	/**
	 * An unbound property matches every context, so the narrowing never fires
	 * where it was not asked for.
	 *
	 * @return void
	 */
	public function testAnUnboundPropertyMatchesEveryContext(): void {
		$declaration = CodedPropertyDeclaration::fromProperty(
			property: [CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'urn:s']]
		);

		$this->assertNotNull($declaration);
		$this->assertTrue(
			$declaration->matchesContext(concept: ['contexts' => ['bezwaar']], context: 'melding')
		);
	}//end testAnUnboundPropertyMatchesEveryContext()

	/**
	 * A concept that is missing a field its scheme requires is reported by
	 * name, and one belonging to a scheme that declares no shape is not.
	 *
	 * @return void
	 */
	public function testAConceptMissingADeclaredFieldIsReportedByName(): void {
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptShape')->willReturn(
			[
				'properties' => [
					'bewaartermijn' => ['type' => 'integer'],
					'grondslag' => ['type' => 'string'],
				],
				'required' => ['bewaartermijn', 'grondslag'],
			]
		);

		$guard = new ConceptShapeGuard(concepts: $concepts, lifecycle: new ConceptLifecycle());

		$conceptSchema = new Schema();
		$conceptSchema->setSlug('concept');

		$errors = $guard->violations(
			object: [
				'uri' => 'urn:vernietigen',
				'inScheme' => 'https://example.org/resultaattypen',
				'fields' => ['bewaartermijn' => 10],
			],
			schema: $conceptSchema
		);

		$this->assertArrayHasKey('grondslag', $errors);
		$this->assertStringContainsString('grondslag', $errors['grondslag']);
		$this->assertStringContainsString('urn:vernietigen', $errors['grondslag']);
	}//end testAConceptMissingADeclaredFieldIsReportedByName()

	/**
	 * A batch reports its invalid concepts by uri, so an import of forty
	 * resultaattypen names the two that fail and imports the other
	 * thirty-eight.
	 *
	 * @return void
	 */
	public function testABatchReportsItsInvalidConceptsByUri(): void {
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptShape')->willReturn(
			['properties' => ['grondslag' => ['type' => 'string']], 'required' => ['grondslag']]
		);

		$guard = new ConceptShapeGuard(concepts: $concepts, lifecycle: new ConceptLifecycle());

		$invalid = $guard->invalidInBatch(
			concepts: [
				['uri' => 'urn:a', 'fields' => ['grondslag' => 'Selectielijst']],
				['uri' => 'urn:b', 'fields' => []],
			],
			schemeUri: 'https://example.org/resultaattypen'
		);

		$this->assertSame(['urn:b' => ['grondslag']], $invalid);
	}//end testABatchReportsItsInvalidConceptsByUri()

	/**
	 * An ordinary object is never shape-checked, however much it looks like a
	 * concept.
	 *
	 * @return void
	 */
	public function testAnOrdinaryObjectIsNeverShapeChecked(): void {
		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->expects($this->never())->method('conceptShape');

		$guard = new ConceptShapeGuard(concepts: $concepts, lifecycle: new ConceptLifecycle());

		$zaak = new Schema();
		$zaak->setSlug('zaak');

		$this->assertSame(
			[],
			$guard->violations(
				object: ['inScheme' => 'https://example.org/s', 'fields' => []],
				schema: $zaak
			)
		);
	}//end testAnOrdinaryObjectIsNeverShapeChecked()
}//end class
