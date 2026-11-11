<?php

/**
 * AssignAction and SetPropertiesAction: declarations, parameter checks, plan and failing write.
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

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\AbstractPropertyWriteAction;
use OCA\OpenRegister\BulkAction\AssignAction;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\BulkAction\SetPropertiesAction;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of the two property-writing bulk actions and their shared base.
 */
class PropertyWriteActionsBehaviourTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objectService;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectOperationsInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function setProperties(): SetPropertiesAction {
		return new SetPropertiesAction($this->objectService, $this->logger);
	}

	private function assign(): AssignAction {
		return new AssignAction($this->objectService, $this->logger);
	}

	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-4');
		$object->setRegister('3');
		$object->setSchema('5');
		$object->setObject($data);

		return $object;
	}

	/**
	 * Run validateParameters and say whether it accepted.
	 */
	private function verdict(AbstractPropertyWriteAction $action, array $parameters): string {
		try {
			$action->validateParameters($parameters);
		} catch (InvalidArgumentException $exception) {
			return 'refused: '.$exception->getMessage();
		}

		return 'accepted';
	}

	public function testTheLabelsAndDescriptionsSayWhatEachActionDoes(): void {
		$this->assertSame('Set properties', $this->setProperties()->getLabel());
		$this->assertStringContainsString('already carry those values are skipped', $this->setProperties()->getDescription());
		$this->assertSame('Hand over to a handler', $this->assign()->getLabel());
		$this->assertStringContainsString('reason you type is recorded', $this->assign()->getDescription());
	}

	public function testBothActionsStayUndoableForSevenDays(): void {
		$this->assertSame(604800, $this->setProperties()->getReversalWindow());
		$this->assertSame(604800, $this->assign()->getReversalWindow());
	}

	public function testSetPropertiesAcceptsANonEmptyPropertiesObject(): void {
		$this->assertSame('accepted', $this->verdict($this->setProperties(), ['properties' => ['status' => 'x']]));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function badPropertiesProvider(): array {
		return [
			'missing' => [[]],
			'empty' => [['properties' => []]],
			'not an array' => [['properties' => 'status=x']],
		];
	}

	/**
	 * @dataProvider badPropertiesProvider
	 */
	public function testSetPropertiesRefusesAMissingEmptyOrScalarProperties(array $parameters): void {
		$this->assertSame(
			'refused: The action openregister:set-properties needs a properties object holding at least one property to write.',
			$this->verdict($this->setProperties(), $parameters)
		);
	}

	public function testAssignAcceptsAPropertyAndAValueEvenWhenTheValueIsNull(): void {
		$this->assertSame('accepted', $this->verdict($this->assign(), ['property' => 'handler', 'value' => null]));
	}

	public function testAssignRefusesAMissingValue(): void {
		$this->assertSame(
			'refused: The action openregister:assign needs a value naming the handler to hand the objects to.',
			$this->verdict($this->assign(), ['property' => 'handler'])
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function badPropertyProvider(): array {
		return [
			'missing' => [['value' => 'ann']],
			'blank' => [['property' => '   ', 'value' => 'ann']],
			'not a string' => [['property' => 12, 'value' => 'ann']],
		];
	}

	/**
	 * @dataProvider badPropertyProvider
	 */
	public function testAssignRefusesAMissingBlankOrNonStringProperty(array $parameters): void {
		$this->assertSame(
			'refused: The action openregister:assign needs a property naming the field that holds the handler.',
			$this->verdict($this->assign(), $parameters)
		);
	}

	public function testAnAssignWithoutAPropertyHasNothingToWrite(): void {
		$this->objectService->expects($this->never())->method('patchObject');

		$result = $this->assign()->apply(step: new BulkStep(object: $this->object(['handler' => 'bob']), parameters: ['value' => 'ann'], commit: true, actor: null));

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('the object already names this handler', $result->getReason());
	}

	public function testASetPropertiesWithScalarPropertiesHasNothingToWrite(): void {
		$this->objectService->expects($this->never())->method('patchObject');

		$result = $this->setProperties()->apply(step: new BulkStep(object: $this->object(['status' => 'open']), parameters: ['properties' => 'status'], commit: true, actor: null));

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('the object already carries these values', $result->getReason());
	}

	public function testANullTargetOnAnAbsentKeyIsStillAWrite(): void {
		$actor = $this->createMock(IUser::class);
		$this->objectService->expects($this->once())
			->method('patchObject')
			->with('zaak-4', ['handler' => null], '3', '5', true, true, $actor)
			->willReturn(new ObjectEntity());

		$result = $this->assign()->apply(
			step: new BulkStep(
				object: $this->object(['status' => 'open']),
				parameters: ['property' => 'handler', 'value' => null],
				commit: true,
				actor: $actor
			)
		);

		$this->assertTrue($result->isApplied());
	}

	public function testALooselyEqualValueIsStillAWrite(): void {
		$result = $this->setProperties()->apply(step: new BulkStep(object: $this->object(['count' => '3']), parameters: ['properties' => ['count' => 3]], commit: false, actor: null));

		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
	}

	public function testTheAssignPlanRecordsTheCurrentHandler(): void {
		$plan = $this->assign()->reversalPlanFor(
			$this->object(['handler' => 'bob']),
			['property' => 'handler', 'value' => 'ann']
		);

		$this->assertSame(['prior' => ['handler' => 'bob'], 'applied' => ['handler' => 'ann']], $plan);
	}

	public function testTheSetPropertiesPlanRecordsNullForAKeyTheObjectDoesNotCarry(): void {
		$plan = $this->setProperties()->reversalPlanFor(
			$this->object(['status' => 'open', 'untouched' => 1]),
			['properties' => ['status' => 'closed', 'closedBy' => 'ann']]
		);

		$this->assertSame(
			[
				'prior' => ['status' => 'open', 'closedBy' => null],
				'applied' => ['status' => 'closed', 'closedBy' => 'ann'],
			],
			$plan
		);
	}

	public function testAFailingWriteIsLoggedUnderTheConcreteActionsId(): void {
		$this->objectService->method('patchObject')->willThrowException(new RuntimeException('validation failed'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[BulkAction] Write failed for object',
				['action' => 'openregister:assign', 'object' => 'zaak-4', 'error' => 'validation failed']
			);

		$result = $this->assign()->apply(step: new BulkStep(object: $this->object([]), parameters: ['property' => 'handler', 'value' => 'ann'], commit: true, actor: null));

		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		$this->assertSame('validation failed', $result->getReason());
	}
	public function testSetPropertiesIsUnjustifiedAndHomogeneityGuardedWhileAssignIsTheReverse(): void {
		$this->assertSame('openregister:set-properties', $this->setProperties()->getId());
		$this->assertFalse($this->setProperties()->requiresJustification());
		$this->assertSame(['homogeneity'], $this->setProperties()->getGuards());
		$this->assertSame('openregister:assign', $this->assign()->getId());
		$this->assertTrue($this->assign()->requiresJustification());
		$this->assertSame([], $this->assign()->getGuards());
	}
}
