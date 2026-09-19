<?php

/**
 * A reference that narrows, and the one way narrowing goes wrong quietly.
 *
 * 🔴 THE FAILURE THIS FILE IS SHAPED AROUND IS "NO OPTIONS" TURNING INTO
 * "EVERY OPTION". A `contactPerson` filtered on the organisation chosen on the
 * case is a picker that must be EMPTY until an organisation is chosen. The
 * tempting implementation drops the unresolved condition and runs the query
 * without it, which offers the contacts of every organisation on the instance.
 * It is a disclosure, and on screen it looks exactly like a working picker:
 * a list of names, in a dropdown, where a list of names belongs.
 *
 * So the resolver answers a filter or a `needs`, never both and never a partial
 * filter, and the tests below assert the emptiness as hard as they assert the
 * filtering.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterException;
use PHPUnit\Framework\TestCase;

/**
 * The declaration, its save-time checks and its resolution.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 */
class ReferenceFilterDeclarationTest extends TestCase {

	/**
	 * The worked example: a contact narrowed by the case's organisation.
	 *
	 * @return array<string, mixed> The property definition.
	 */
	private function contactPerson(): array {
		return [
			'type' => 'string',
			'$ref' => 'contact',
			ReferenceFilterDeclaration::ANNOTATION => [
				['field' => 'organisation', 'op' => 'eq', 'from' => 'organisatie'],
			],
		];
	}

	/**
	 * A property with no annotation declares no filter.
	 *
	 * The control. Without it every refusal below is satisfied by a reader that
	 * refuses everything.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutTheAnnotationDeclaresNothing(): void {
		$this->assertNull(
			ReferenceFilterDeclaration::fromProperty(property: ['type' => 'string', '$ref' => 'contact'])
		);
	}//end testAPropertyWithoutTheAnnotationDeclaresNothing()

	/**
	 * The worked example parses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public function testTheWorkedExampleParses(): void {
		$declaration = ReferenceFilterDeclaration::fromProperty(property: $this->contactPerson());

		$this->assertNotNull($declaration);
		$this->assertSame(
			[['field' => 'organisation', 'op' => 'eq', 'from' => 'organisatie']],
			$declaration->conditions
		);
	}//end testTheWorkedExampleParses()

	/**
	 * A filter on a property that references nothing is refused.
	 *
	 * A rule on a plain string is a rule nothing reads, and the author who
	 * wrote it believes their field is filtered.
	 *
	 * @return void
	 */
	public function testAFilterOnANonReferenceIsRefused(): void {
		$this->expectException(ReferenceFilterException::class);

		ReferenceFilterDeclaration::fromProperty(
			property: [
				'type' => 'string',
				ReferenceFilterDeclaration::ANNOTATION => [
					['field' => 'organisation', 'from' => 'organisatie'],
				],
			],
			path: '/properties/contactPersoon'
		);
	}//end testAFilterOnANonReferenceIsRefused()

	/**
	 * A condition missing an operand, or using an unknown operator, is refused.
	 *
	 * @return void
	 */
	public function testAnUnusableConditionIsRefused(): void {
		foreach (
			[
				[['field' => 'organisation']],
				[['from' => 'organisatie']],
				[['field' => 'organisation', 'from' => 'organisatie', 'op' => 'like']],
				'not-a-list',
				[],
			] as $raw
		) {
			try {
				ReferenceFilterDeclaration::fromProperty(
					property: ['type' => 'string', '$ref' => 'contact', ReferenceFilterDeclaration::ANNOTATION => $raw],
					path: '/properties/contactPersoon'
				);
				$this->fail('an unusable filter must be refused: ' . json_encode($raw));
			} catch (ReferenceFilterException $refusal) {
				$this->assertStringContainsString('/properties/contactPersoon', $refusal->getMessage());
			}
		}
	}//end testAnUnusableConditionIsRefused()

	/**
	 * A filter naming a property neither schema declares is refused at save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public function testAnOperandNeitherSchemaDeclaresIsRefused(): void {
		$declaration = ReferenceFilterDeclaration::fromProperty(property: $this->contactPerson());

		// The operand this record is read from is missing.
		try {
			$declaration->assertOperandsExist(
				ownProperties: ['titel' => []],
				farProperties: ['organisation' => []],
				path: '/properties/contactPersoon'
			);
			$this->fail('a filter reading a property this schema does not declare must be refused');
		} catch (ReferenceFilterException $refusal) {
			// It has to say WHICH schema is missing it, or an author checks the
			// wrong one first every time.
			$this->assertStringContainsString('organisatie', $refusal->getMessage());
			$this->assertStringContainsString('this schema does not declare it', $refusal->getMessage());
		}

		// The field it matches on is missing from the far schema.
		try {
			$declaration->assertOperandsExist(
				ownProperties: ['organisatie' => []],
				farProperties: ['naam' => []],
				path: '/properties/contactPersoon'
			);
			$this->fail('a filter matching on a property the far schema does not declare must be refused');
		} catch (ReferenceFilterException $refusal) {
			$this->assertStringContainsString('organisation', $refusal->getMessage());
			$this->assertStringContainsString('referenced schema', $refusal->getMessage());
		}

		// And the worked example, where both are declared, passes.
		$declaration->assertOperandsExist(
			ownProperties: ['organisatie' => []],
			farProperties: ['organisation' => []],
			path: '/properties/contactPersoon'
		);
		$this->addToAssertionCount(1);
	}//end testAnOperandNeitherSchemaDeclaresIsRefused()

	/**
	 * A resolved operand becomes a filter over the referenced schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public function testAResolvedOperandBecomesAFilter(): void {
		$answer = ReferenceFilterDeclaration::fromProperty(property: $this->contactPerson())
			->resolve(record: ['organisatie' => 'org-7']);

		$this->assertSame(['organisation' => 'org-7'], $answer['filter']);
		$this->assertSame([], $answer['needs']);
	}//end testAResolvedOperandBecomesAFilter()

	/**
	 * An unresolved operand offers NOTHING and names what it needs.
	 *
	 * 🔴 THE ONE THAT MATTERS. Every empty value a record can hold is checked,
	 * because "not chosen yet" arrives as null from one client, as an empty
	 * string from a form post and as an empty array from a multi-select, and a
	 * resolver that only knew null would open the picker on the other two.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-an-unresolved-filter-offers-nothing-and-names-what-it-needs-req-fuc-004
	 */
	public function testAnUnresolvedOperandOffersNothingAndSaysWhatItNeeds(): void {
		$declaration = ReferenceFilterDeclaration::fromProperty(property: $this->contactPerson());

		foreach ([[], ['organisatie' => null], ['organisatie' => ''], ['organisatie' => []]] as $record) {
			$answer = $declaration->resolve(record: $record);

			$this->assertSame(
				[],
				$answer['filter'],
				'an unresolved operand must produce NO filter; a filter of [] is every contact on the instance'
			);
			$this->assertSame(['organisatie'], $answer['needs']);
		}
	}//end testAnUnresolvedOperandOffersNothingAndSaysWhatItNeeds()

	/**
	 * One unresolved condition drops the WHOLE filter, not just itself.
	 *
	 * Half a filter is wider than the filter, and wider is the direction that
	 * discloses. This is the assertion that stops somebody "improving" the
	 * resolver by keeping the conditions it could resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-an-unresolved-filter-offers-nothing-and-names-what-it-needs-req-fuc-004
	 */
	public function testOneUnresolvedConditionDropsTheWholeFilter(): void {
		$declaration = ReferenceFilterDeclaration::fromProperty(
			property: [
				'type' => 'string',
				'$ref' => 'contact',
				ReferenceFilterDeclaration::ANNOTATION => [
					['field' => 'organisation', 'from' => 'organisatie'],
					['field' => 'afdeling', 'from' => 'afdeling'],
				],
			]
		);

		$answer = $declaration->resolve(record: ['organisatie' => 'org-7']);

		$this->assertSame([], $answer['filter']);
		$this->assertSame(['afdeling'], $answer['needs']);
	}//end testOneUnresolvedConditionDropsTheWholeFilter()

	/**
	 * The refusal answers as a vocabulary refusal, so every save path knows it.
	 *
	 * @return void
	 */
	public function testTheRefusalIsAVocabularyRefusal(): void {
		$this->expectException(PropertyVocabularyException::class);

		ReferenceFilterDeclaration::fromProperty(
			property: ['type' => 'string', '$ref' => 'contact', ReferenceFilterDeclaration::ANNOTATION => 'nope'],
			path: '/properties/contactPersoon'
		);
	}//end testTheRefusalIsAVocabularyRefusal()
}//end class
