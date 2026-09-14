<?php

/**
 * Unit tests for the two built-in bulk actions.
 *
 * What each action declares is the whole point of the declaration: the
 * attribute write carries the homogeneity guard and needs no reason, the
 * redistribution needs a reason and carries no guard (D-6, D-7). Underneath
 * both, the rehearsal and the commit take the same path and differ only in
 * whether patchObject is called (D-1).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BulkAction
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\BulkAction;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\AssignAction;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\SetPropertiesAction;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PropertyWriteActionsTest extends TestCase {

	private ObjectService $objectService;

	/**
	 * Every patch handed to patchObject during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	protected function setUp(): void {
		parent::setUp();

		$this->patches = [];
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) {
				$this->patches[] = ['uuid' => $objectId, 'data' => $data];

				return new ObjectEntity();
			}
		);
	}

	private function object(array $data = ['status' => 'open']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-1');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject($data);

		return $object;
	}

	private function setProperties(): SetPropertiesAction {
		return new SetPropertiesAction($this->objectService, new NullLogger());
	}

	private function assign(): AssignAction {
		return new AssignAction($this->objectService, new NullLogger());
	}

	public function testTheAttributeWriteDeclaresTheHomogeneityGuardAndNoReason(): void {
		$action = $this->setProperties();

		$this->assertSame('openregister:set-properties', $action->getId());
		$this->assertSame([BulkActionInterface::GUARD_HOMOGENEITY], $action->getGuards());
		$this->assertFalse($action->requiresJustification());
	}

	public function testTheRedistributionDeclaresAReasonAndNoGuard(): void {
		$action = $this->assign();

		$this->assertSame('openregister:assign', $action->getId());
		$this->assertSame([], $action->getGuards());
		$this->assertTrue($action->requiresJustification());
	}

	public function testTheRehearsalWritesNothing(): void {
		$result = $this->setProperties()->apply($this->object(), ['properties' => ['status' => 'closed']], false);

		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
		$this->assertSame([], $this->patches, 'a rehearsal must not reach the write path');
	}

	public function testTheCommitWritesTheSamePatchTheRehearsalPromised(): void {
		$action = $this->setProperties();
		$parameters = ['properties' => ['status' => 'closed']];

		$rehearsed = $action->apply($this->object(), $parameters, false);
		$committed = $action->apply($this->object(), $parameters, true);

		$this->assertSame($rehearsed->getOutcome(), $committed->getOutcome());
		$this->assertCount(1, $this->patches);
		$this->assertSame('zaak-1', $this->patches[0]['uuid']);
		$this->assertSame(['status' => 'closed'], $this->patches[0]['data']);
	}

	public function testAnObjectThatAlreadyCarriesTheValuesIsSkippedWithAReason(): void {
		$result = $this->setProperties()->apply(
			$this->object(['status' => 'closed']),
			['properties' => ['status' => 'closed']],
			true
		);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('the object already carries these values', $result->getReason());
		$this->assertSame([], $this->patches);
	}

	public function testAnObjectAlreadyNamingTheHandlerIsSkipped(): void {
		$result = $this->assign()->apply(
			$this->object(['assignee' => 'fatima']),
			['property' => 'assignee', 'value' => 'fatima'],
			true
		);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('the object already names this handler', $result->getReason());
	}

	public function testTheRedistributionWritesTheNamedProperty(): void {
		$result = $this->assign()->apply(
			$this->object(['assignee' => 'hans']),
			['property' => 'assignee', 'value' => 'fatima'],
			true
		);

		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
		$this->assertSame(['assignee' => 'fatima'], $this->patches[0]['data']);
	}

	public function testAWriteThatThrowsIsFailedNotSkipped(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('patchObject')->willThrowException(new \RuntimeException('the object is locked'));

		$action = new SetPropertiesAction($objectService, new NullLogger());
		$result = $action->apply($this->object(), ['properties' => ['status' => 'closed']], true);

		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		$this->assertSame('the object is locked', $result->getReason());
	}

	public function testParametersAreCheckedBeforeAJobIsCreated(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->setProperties()->validateParameters(['properties' => []]);
	}

	public function testTheRedistributionNeedsBothAPropertyAndAValue(): void {
		$this->assign()->validateParameters(['property' => 'assignee', 'value' => null]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs a value');

		$this->assign()->validateParameters(['property' => 'assignee']);
	}
}
