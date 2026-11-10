<?php

/**
 * Starting an object action over a selection as a bulk job: who it runs as,
 * what it is told to run, which rows, and the register id resolved or not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use InvalidArgumentException;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\BulkJob\BulkJobActionCall;
use OCA\OpenRegister\Service\BulkJob\BulkJobSelection;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRequest;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSelection;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ObjectActionBulk}.
 */
class ObjectActionBulkTest extends TestCase {

	/**
	 * The bulk job service.
	 *
	 * @var BulkJobService&MockObject
	 */
	private BulkJobService&MockObject $jobs;

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;

	/**
	 * The gate naming the acting user.
	 *
	 * @var ObjectActionGate&MockObject
	 */
	private ObjectActionGate&MockObject $gate;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->jobs = $this->createMock(BulkJobService::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->gate = $this->createMock(ObjectActionGate::class);
	}//end setUp()

	/**
	 * A selection of two objects of schema 9 in register `cases`.
	 *
	 * @return ObjectActionSelection The selection.
	 */
	private function selection(): ObjectActionSelection {
		$schema = new Schema();
		$schema->setId(9);

		return new ObjectActionSelection(schema: $schema, register: 'cases', schemaRef: 'case', ids: ['o-1', 'o-2']);
	}//end selection()

	/**
	 * The bulk job runs the run-action action as the signed-in user over the
	 * selected ids, with the register resolved to its id.
	 *
	 * @return void
	 */
	public function testStartCreatesAJobAsTheSignedInUser(): void {
		$register = new Register();
		$register->setId(4);
		$this->gate->method('userId')->willReturn('noor');
		$this->registers->method('find')->with('cases', false, false)->willReturn($register);
		$job = new BulkJob();
		$this->jobs->expects($this->once())
			->method('create')
			->willReturnCallback(
				function (BulkJobActionCall $call, BulkJobSelection $selection, string $actorUid, ?string $justification) use ($job): BulkJob {
					$this->assertSame('openregister:run-action', $call->actionId);
					$this->assertSame(['action' => 'close', 'inputs' => ['reason' => 'done'], 'register' => 'cases', 'schema' => 'case'], $call->parameters);
					$this->assertSame(['ids' => ['o-1', 'o-2']], $selection->criteria);
					$this->assertSame([4, 9], [$selection->registerId, $selection->schemaId]);
					$this->assertSame('noor', $actorUid);
					$this->assertNull($justification);

					return $job;
				}
			);

		$bulk = new ObjectActionBulk(jobs: $this->jobs, registers: $this->registers, gate: $this->gate);

		$this->assertSame($job, $bulk->start(selection: $this->selection(), request: new ObjectActionRequest(key: 'close', given: ['reason' => 'done'])));
	}//end testStartCreatesAJobAsTheSignedInUser()

	/**
	 * A register that cannot be resolved leaves the job's register id empty.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterLeavesTheRegisterIdEmpty(): void {
		$this->gate->method('userId')->willReturn('noor');
		$this->registers->method('find')->willThrowException(new DoesNotExistException('no register'));
		$this->jobs->expects($this->once())
			->method('create')
			->with($this->anything(), $this->callback(static fn (BulkJobSelection $selection): bool => $selection->registerId === null && $selection->schemaId === 9))
			->willReturn(new BulkJob());

		(new ObjectActionBulk(jobs: $this->jobs, registers: $this->registers, gate: $this->gate))->start(selection: $this->selection(), request: new ObjectActionRequest(key: 'close', given: []));
	}//end testAnUnresolvableRegisterLeavesTheRegisterIdEmpty()

	/**
	 * Without a signed-in user no job is created.
	 *
	 * @return void
	 */
	public function testWithoutASignedInUserNoJobIsCreated(): void {
		$this->gate->method('userId')->willReturn(null);
		$this->jobs->expects($this->never())->method('create');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs a signed-in user');
		(new ObjectActionBulk(jobs: $this->jobs, registers: $this->registers, gate: $this->gate))->start(selection: $this->selection(), request: new ObjectActionRequest(key: 'close', given: []));
	}//end testWithoutASignedInUserNoJobIsCreated()
}//end class
