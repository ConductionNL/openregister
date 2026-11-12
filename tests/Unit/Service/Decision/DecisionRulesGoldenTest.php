<?php

/**
 * Golden characterisation test for the review-annotation refusals, the review
 * policy a declaration yields, and the refusals of recording a decision.
 *
 * Pinned on the code before ReviewAnnotationValidator::badValues(),
 * ReviewPolicy::fromDeclaration() and DecisionService::record() were split into
 * named steps: exact codes and messages in order, the policy fields (supervisors
 * trimmed and de-duplicated), and which decisions are refused before any insert.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Decision
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Decision;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\DecisionMapperInterface;
use OCA\OpenRegister\Service\Decision\DecisionService;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use PHPUnit\Framework\TestCase;

final class DecisionRulesGoldenTest extends TestCase {

	public function testEveryBadValueIsRefusedInOrder(): void {
		$validator = new ReviewAnnotationValidator();
		$cases     = [
			[['ownership' => 7], [['review-ownership', 'Ownership "7" is not open or decider.']]],
			[['ownership' => ['x']], [['review-ownership', 'Ownership "array" is not open or decider.']]],
			[['ownership' => null, 'supervisors' => ['a', 3]], [['review-supervisors', 'Supervisors must be a list of group ids.']]],
			[['ownership' => false], [['review-ownership', 'Ownership "" is not open or decider.']]],
			[
				['supervisors' => 'g', 'hideOthers' => 1],
				[['review-supervisors', 'Supervisors must be a list of group ids.'], ['review-hide-others', 'hideOthers must be true or false.']],
			],
			[['ownership' => 'decider', 'supervisors' => ['a', 'b'], 'hideOthers' => true], []],
			[[], []],
		];
		foreach ($cases as [$declaration, $expected]) {
			$errors = $validator->validate(configuration: [ReviewPolicy::ANNOTATION => $declaration]);
			$pairs  = array_map(static fn (array $error): array => [$error['code'], $error['message']], $errors);
			$this->assertSame($expected, $pairs, json_encode($declaration));
		}
	}

	public function testThePolicyADeclarationYields(): void {
		$policy = ReviewPolicy::fromDeclaration(
			declaration: ['ownership' => 'decider', 'supervisors' => [' a ', 'b', 'a', '', 4, '  '], 'hideOthers' => true]
		);
		$this->assertSame([true, ['a', 'b'], true], [$policy->isDeciderOwned(), $policy->getSupervisors(), $policy->hidesOthers()]);

		$open = ReviewPolicy::fromDeclaration(declaration: ['supervisors' => 'g', 'hideOthers' => 'yes']);
		$this->assertSame([false, ['g'], false], [$open->isDeciderOwned(), $open->getSupervisors(), $open->hidesOthers()]);

		$none = ReviewPolicy::fromDeclaration(declaration: 'not an object');
		$this->assertSame([false, [], false], [$none->isDeciderOwned(), $none->getSupervisors(), $none->hidesOthers()]);
	}

	public function testRecordingRefusesAnIncompleteDecisionBeforeAnyInsert(): void {
		$mapper = $this->createMock(DecisionMapperInterface::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$service = new DecisionService($mapper);

		$refusals = [];
		$incomplete = [
			['subjectType', ''],
			['subjectId', ' '],
			['verdict', ''],
			['decidedBy', ''],
			['note', str_repeat('n', DecisionService::MAX_NOTE + 1)],
		];
		foreach ($incomplete as [$field, $value]) {
			try {
				$service->record($this->decision(field: $field, value: $value));
				$refusals[] = $field . ': recorded';
			} catch (InvalidArgumentException $e) {
				$refusals[] = $field . ': ' . $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'subjectType: A decision needs subjectType.',
				'subjectId: A decision needs subjectId.',
				'verdict: A decision needs verdict.',
				'decidedBy: A decision needs decidedBy.',
				'note: A decision note is at most ' . DecisionService::MAX_NOTE . ' characters.',
			],
			$refusals
		);

		$service->record($this->decision(field: 'note', value: str_repeat('n', DecisionService::MAX_NOTE)));
	}

	private function decision(string $field, string $value): Decision {
		$decision = new Decision();
		$decision->setSubjectType('relation');
		$decision->setSubjectId('17');
		$decision->setVerdict('approved');
		$decision->setDecidedBy('jan');
		$decision->{'set' . ucfirst($field)}($value);

		return $decision;
	}
}//end class
