<?php

/**
 * RestorePriorValuesAction: declarations, plan, memoised lookup and a failing write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\BulkAction\BulkObjectAccess;
use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\RestorePriorValuesAction;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * Behaviour of the reversal action beyond its four headline outcomes.
 */
class RestorePriorValuesActionBehaviourTest extends TestCase {

	private BulkJobMemberMapperInterface&MockObject $memberMapper;

	private ObjectOperationsInterface&MockObject $objectService;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->memberMapper = $this->createMock(BulkJobMemberMapperInterface::class);
		$this->objectService = $this->createMock(ObjectOperationsInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function action(): RestorePriorValuesAction {
		return new RestorePriorValuesAction($this->memberMapper, new BulkObjectAccess($this->objectService, $this->createMock(SchemaMapperInterface::class), $this->logger));
	}

	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-9');
		$object->setRegister('11');
		$object->setSchema('22');
		$object->setObject($data);

		return $object;
	}

	private function record(?array $prior, ?array $applied): BulkJobMember {
		$member = new BulkJobMember();
		$member->setJobId(7);
		$member->setObjectUuid('case-9');
		$member->setPriorValues($prior);
		$member->setAppliedValues($applied);

		return $member;
	}

	private function parameters(): array {
		return [RestorePriorValuesAction::PARAM_JOB => 7];
	}

	public function testItDeclaresItselfAsAJustifiedUnguardedUndo(): void {
		$action = $this->action();

		$this->assertSame('openregister:restore-prior-values', $action->getId());
		$this->assertSame('Undo a bulk action', $action->getLabel());
		$this->assertStringContainsString('left alone and reported by name', $action->getDescription());
		$this->assertTrue($action->requiresJustification());
		$this->assertSame([], $action->getGuards());
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function jobParameterProvider(): array {
		return [
			'positive int is accepted' => [[RestorePriorValuesAction::PARAM_JOB => 7], 'accepted'],
			'one is accepted' => [[RestorePriorValuesAction::PARAM_JOB => 1], 'accepted'],
			'zero is refused' => [[RestorePriorValuesAction::PARAM_JOB => 0], 'refused'],
			'negative is refused' => [[RestorePriorValuesAction::PARAM_JOB => -3], 'refused'],
			'numeric string is refused' => [[RestorePriorValuesAction::PARAM_JOB => '7'], 'refused'],
			'missing is refused' => [[], 'refused'],
		];
	}

	/**
	 * @dataProvider jobParameterProvider
	 */
	public function testOnlyAPositiveIntegerJobIdIsAccepted(array $parameters, string $expected): void {
		try {
			$this->action()->validateParameters($parameters);
			$verdict = 'accepted';
		} catch (InvalidArgumentException $exception) {
			$this->assertStringContainsString('reversesJobId', $exception->getMessage());
			$verdict = 'refused';
		}

		$this->assertSame($expected, $verdict);
	}

	public function testTheReversalWindowIsSevenDays(): void {
		$this->assertSame(604800, $this->action()->getReversalWindow());
	}

	public function testThePlanRecordsTheCurrentValueOfEveryRestoredKeyAndNullForAMissingOne(): void {
		$this->memberMapper->method('findByJobAndObject')
			->willReturn($this->record(['status' => 'open', 'handler' => 'ann'], ['status' => 'closed']));

		$plan = $this->action()->reversalPlanFor($this->object(['status' => 'closed', 'other' => 1]), $this->parameters());

		$this->assertSame(
			[
				'prior' => ['status' => 'closed', 'handler' => null],
				'applied' => ['status' => 'open', 'handler' => 'ann'],
			],
			$plan
		);
	}

	public function testThePlanIsEmptyForAMemberTheOriginalJobNeverWrote(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn(null);

		$plan = $this->action()->reversalPlanFor($this->object(['status' => 'closed']), $this->parameters());

		$this->assertSame(['prior' => [], 'applied' => []], $plan);
	}

	public function testTheMemberRowIsLookedUpOncePerJobAndObject(): void {
		$this->memberMapper->expects($this->once())
			->method('findByJobAndObject')
			->with(7, 'case-9')
			->willReturn($this->record(['status' => 'open'], ['status' => 'closed']));

		$action = $this->action();
		$object = $this->object(['status' => 'closed']);

		$action->reversalPlanFor($object, $this->parameters());
		$rehearsal = $action->apply($object, $this->parameters(), false);
		$commit = $action->apply($object, $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $rehearsal->getOutcome());
		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $commit->getOutcome());
	}

	public function testAMissingRowIsAlsoMemoised(): void {
		$this->memberMapper->expects($this->once())->method('findByJobAndObject')->willReturn(null);

		$action = $this->action();
		$object = $this->object(['status' => 'closed']);
		$first = $action->apply($object, $this->parameters(), false);
		$second = $action->apply($object, $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $first->getOutcome());
		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $second->getOutcome());
	}

	public function testTheCommitWritesThePriorValuesAsTheJobsActorOnTheObjectsRegisterAndSchema(): void {
		$actor = $this->createMock(IUser::class);
		$this->memberMapper->method('findByJobAndObject')
			->willReturn($this->record(['status' => 'open'], ['status' => 'closed']));
		$this->objectService->expects($this->once())
			->method('patchObject')
			->with(new ObjectPatch(objectId: 'case-9', data: ['status' => 'open'], target: new ObjectFindTarget(register: '11', schema: '22'), currentUser: $actor), true, true)
			->willReturn(new ObjectEntity());

		$result = $this->action()->apply($this->object(['status' => 'closed']), $this->parameters(), true, $actor);

		$this->assertTrue($result->isApplied());
	}

	public function testAWriteThatThrowsIsReportedAsFailedAndLogged(): void {
		$this->memberMapper->method('findByJobAndObject')
			->willReturn($this->record(['status' => 'open'], ['status' => 'closed']));
		$this->objectService->method('patchObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[BulkAction] Restoring the prior value failed for object',
				[
					'action' => 'openregister:restore-prior-values',
					'object' => 'case-9',
					'error' => 'locked',
				]
			);

		$result = $this->action()->apply($this->object(['status' => 'closed']), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		$this->assertSame('locked', $result->getReason());
	}

	public function testARowWithAnEmptyPriorValueSetHasNothingToWriteBack(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn($this->record([], ['status' => 'closed']));
		$this->objectService->expects($this->never())->method('patchObject');

		$result = $this->action()->apply($this->object(['status' => 'closed']), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('the object already carries the values it had before', $result->getReason());
	}

	public function testABooleanIsNotTreatedAsTheNumberOne(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn($this->record(['flag' => 0], ['flag' => 1]));
		$this->objectService->expects($this->never())->method('patchObject');

		$result = $this->action()->apply($this->object(['flag' => true]), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame(
			'not reversible: flag changed after the original job wrote it, and a later change is never overwritten',
			$result->getReason()
		);
	}

	public function testAFloatAndAnIntegerStringOfTheSameNumberAreTheSameValue(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn($this->record(['amount' => 5], ['amount' => '12']));

		$result = $this->action()->apply($this->object(['amount' => 12.0]), $this->parameters(), false);

		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
	}

	public function testANonNumericStringIsNeverEqualToANumber(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn($this->record(['amount' => 5], ['amount' => 12]));

		$result = $this->action()->apply($this->object(['amount' => 'twelve']), $this->parameters(), false);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertStringStartsWith('not reversible: amount', (string)$result->getReason());
	}
	public function testAnObjectAlreadyCarryingThePriorValueIsSkippedBeforeTheEditCheck(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn($this->record(['status' => 'open'], ['status' => 'closed']));
		$this->objectService->expects($this->never())->method('patchObject');

		$result = $this->action()->apply($this->object(['status' => 'open']), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('the object already carries the values it had before', $result->getReason());
	}

	public function testARowWithoutPriorValuesCountsAsNoRow(): void {
		$this->memberMapper->method('findByJobAndObject')->willReturn($this->record(null, ['status' => 'closed']));
		$this->objectService->expects($this->never())->method('patchObject');

		$result = $this->action()->apply($this->object(['status' => 'closed']), $this->parameters(), true);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame(
			'the job being undone never wrote this object, so there is nothing to write back',
			$result->getReason()
		);
	}
}
