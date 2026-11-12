<?php

/**
 * Characterisation: the exclusive-group refusal of CodedValueRefusals.
 *
 * Pins each branch of exclusiveGroupViolation(): no group refuses nothing, the
 * first concept of a scheme+group is recorded under its label, and a later one
 * in the same group is refused with both labels and the group named.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedValueCheck;
use OCA\OpenRegister\Service\Vocabulary\CodedValueMatch;
use OCA\OpenRegister\Service\Vocabulary\CodedValueRefusals;
use OCA\OpenRegister\Service\Vocabulary\CodedValueSubject;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden outcomes of CodedValueRefusals::exclusiveGroupViolation().
 */
class CodedValueRefusalsExclusiveGroupTest extends TestCase {

	/**
	 * The refusals, with the group read from `group` and the label from `label`.
	 *
	 * @return CodedValueRefusals The refusals.
	 */
	private function refusals(): CodedValueRefusals {
		$lifecycle = $this->createMock(ConceptLifecycle::class);
		$lifecycle->method('exclusiveGroupOf')->willReturnCallback(static fn (array $concept): ?string => $concept['group'] ?? null);

		$hierarchy = $this->createMock(ConceptHierarchy::class);
		$hierarchy->method('labelOf')->willReturnCallback(
			static fn (array $concept, string $language): string => $concept['label'].'@'.$language
		);

		return new CodedValueRefusals(lifecycle: $lifecycle, hierarchy: $hierarchy);
	}//end refusals()

	/**
	 * A subject on the given scheme with a fresh check.
	 *
	 * @param string          $scheme The scheme.
	 * @param CodedValueCheck $check  The check that records claims.
	 *
	 * @return CodedValueSubject The subject.
	 */
	private function subject(string $scheme, CodedValueCheck $check): CodedValueSubject {
		return new CodedValueSubject(property: 'kleur', declaration: new CodedPropertyDeclaration(scheme: $scheme), check: $check);
	}//end subject()

	/**
	 * Judge one concept.
	 *
	 * @param CodedValueRefusals $refusals The refusals.
	 * @param CodedValueSubject  $subject  The subject.
	 * @param array              $concept  The concept.
	 *
	 * @return string|null The refusal.
	 */
	private function judge(CodedValueRefusals $refusals, CodedValueSubject $subject, array $concept): ?string {
		return (new ReflectionMethod(CodedValueRefusals::class, 'exclusiveGroupViolation'))->invoke(
			$refusals,
			$subject,
			new CodedValueMatch(value: $concept['label'], concept: $concept)
		);
	}//end judge()

	/**
	 * No group, first of a group, a second of the same group, another group, and another scheme.
	 *
	 * @return void
	 */
	public function testEachBranchMatchesTheGolden(): void {
		$refusals = $this->refusals();
		$check    = new CodedValueCheck(object: [], asOf: new DateTimeImmutable('2026-01-01T00:00:00Z'));
		$subject  = $this->subject(scheme: 'kleuren', check: $check);

		$this->assertNull($this->judge($refusals, $subject, ['label' => 'los']));
		$this->assertNull($this->judge($refusals, $subject, ['label' => 'rood', 'group' => 'primair']));
		$this->assertSame(
			'The values "rood@nl" and "blauw@nl" both belong to the group "primair", of which only one may be held.',
			$this->judge($refusals, $subject, ['label' => 'blauw', 'group' => 'primair'])
		);
		$this->assertSame(
			'The values "rood@nl" and "geel@nl" both belong to the group "primair", of which only one may be held.',
			$this->judge($refusals, $subject, ['label' => 'geel', 'group' => 'primair'])
		);
		$this->assertNull($this->judge($refusals, $subject, ['label' => 'mat', 'group' => 'afwerking']));
		$this->assertNull($this->judge($refusals, $this->subject(scheme: 'tinten', check: $check), ['label' => 'groen', 'group' => 'primair']));
	}//end testEachBranchMatchesTheGolden()
}//end class
