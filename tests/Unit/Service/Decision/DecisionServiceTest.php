<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\DecisionMapper;
use OCA\OpenRegister\Service\Decision\DecisionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * A decision is recorded as its own object and read back by uuid or subject (REQ-DEC-001).
 *
 * @covers \OCA\OpenRegister\Service\Decision\DecisionService
 * @uses   \OCA\OpenRegister\Db\Decision
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
class DecisionServiceTest extends TestCase {

	/**
	 * Recording stores who, what, on what, the note and what it supersedes.
	 *
	 * @return void
	 */
	public function testItRecordsADecision(): void {
		$mapper = $this->createMock(DecisionMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Decision $decision): Decision {
				$decision->setUuid('d-2');
				return $decision;
			}
		);

		$decision = (new DecisionService(decisionMapper: $mapper))->record(
			subjectType: 'entity-relation',
			subjectId: '7',
			verdict: 'release',
			decidedBy: 'coord',
			note: 'Publicly known name.',
			supersedes: 'd-1'
		);

		self::assertSame(
			[
				'uuid' => 'd-2',
				'subjectType' => 'entity-relation',
				'subjectId' => '7',
				'verdict' => 'release',
				'decidedBy' => 'coord',
				'decidedAt' => null,
				'note' => 'Publicly known name.',
				'supersedes' => 'd-1',
			],
			$decision->jsonSerialize()
		);
	}//end testItRecordsADecision()

	/**
	 * A decision without a decider or a verdict, or with a note past the limit, is refused.
	 *
	 * @return void
	 */
	public function testAnIncompleteDecisionIsRefused(): void {
		$mapper = $this->createMock(DecisionMapper::class);
		$mapper->expects($this->never())->method('insert');
		$service = new DecisionService(decisionMapper: $mapper);

		foreach ([['verdict', ''], ['decidedBy', ' ']] as [$field, $value]) {
			$args = ['subjectType' => 'entity-relation', 'subjectId' => '7', 'verdict' => 'redact', 'decidedBy' => 'j.devries'];
			$args[$field] = $value;
			try {
				$service->record(...$args);
				self::fail('A decision without '.$field.' was stored.');
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString($field, $e->getMessage());
			}
		}

		$this->expectException(InvalidArgumentException::class);
		$service->record(subjectType: 'entity-relation', subjectId: '7', verdict: 'redact', decidedBy: 'j.devries', note: str_repeat('x', DecisionService::MAX_NOTE + 1));
	}//end testAnIncompleteDecisionIsRefused()

	/**
	 * Finding answers the decision, or null for an empty or unknown uuid.
	 *
	 * @return void
	 */
	public function testItFindsByUuidAndListsASubject(): void {
		$found = new Decision();
		$mapper = $this->createMock(DecisionMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($found): Decision {
				if ($uuid === 'd-1') {
					return $found;
				}

				throw new DoesNotExistException('none');
			}
		);
		$mapper->expects($this->once())->method('findForSubject')->with('entity-relation', '7')->willReturn([$found]);
		$service = new DecisionService(decisionMapper: $mapper);

		self::assertSame($found, $service->find(uuid: 'd-1'));
		self::assertNull($service->find(uuid: 'gone'));
		self::assertNull($service->find(uuid: null));
		self::assertNull($service->find(uuid: ''));
		self::assertSame([$found], $service->history(subjectType: 'entity-relation', subjectId: '7'));
	}//end testItFindsByUuidAndListsASubject()
}//end class
