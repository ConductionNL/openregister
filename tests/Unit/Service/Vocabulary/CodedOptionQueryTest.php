<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\CodedOptionQuery}.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Vocabulary\CodedNarrowing;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionQuery;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedValueRules;
use OCA\OpenRegister\Service\Vocabulary\ConceptReading;
use PHPUnit\Framework\TestCase;

/**
 * An options query only narrows to a branch that exists in the scheme.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\CodedOptionQuery
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedNarrowing
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration
 * @uses   \OCA\OpenRegister\Service\Vocabulary\CodedValueRules
 * @uses   \OCA\OpenRegister\Service\Vocabulary\ConceptReading
 */
class CodedOptionQueryTest extends TestCase {

	/**
	 * Build a query whose declaration narrows to the given branch.
	 *
	 * @param string|null $branch The declared branch.
	 *
	 * @return CodedOptionQuery
	 */
	private function query(?string $branch): CodedOptionQuery {
		return new CodedOptionQuery(
			new CodedPropertyDeclaration('urn:scheme', new CodedValueRules(), new CodedNarrowing(branch: $branch)),
			['urn:a' => ['uri' => 'urn:a', 'prefLabel' => 'A']],
			'ctx',
			new ConceptReading('en', new DateTimeImmutable('2026-01-01'))
		);

	}//end query()

	/**
	 * A declared branch present in the scheme is returned.
	 *
	 * @return void
	 */
	public function testExistingBranch(): void {
		$this->assertSame('urn:a', $this->query('urn:a')->existingBranch());

	}//end testExistingBranch()

	/**
	 * No declared branch, or one not in the scheme, is null.
	 *
	 * @return void
	 */
	public function testMissingBranchIsNull(): void {
		$this->assertNull($this->query(null)->existingBranch());
		$this->assertNull($this->query('urn:gone')->existingBranch());

	}//end testMissingBranchIsNull()

	/**
	 * Concept lookup returns the concept or an empty array.
	 *
	 * @return void
	 */
	public function testConceptLookup(): void {
		$query = $this->query(null);

		$this->assertSame(['uri' => 'urn:a', 'prefLabel' => 'A'], $query->concept('urn:a'));
		$this->assertSame([], $query->concept('urn:b'));
		$this->assertSame('ctx', $query->context);

	}//end testConceptLookup()
}//end class
