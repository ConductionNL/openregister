<?php

/**
 * AppHost scheduling: compiled flow storage tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\CompiledScheduleFlow;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleCadence;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleCall;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

/**
 * A compiled flow passes the trigger validator before anything is written.
 *
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\CompiledScheduleFlow
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ScheduleFlowStoreTest extends TestCase {
	private FlowMapper&MockObject $mapper;

	private FlowTriggerValidator&MockObject $validator;

	private ScheduleFlowStore $store;

	protected function setUp(): void {
		$this->mapper = $this->createMock(FlowMapper::class);
		$this->validator = $this->createMock(FlowTriggerValidator::class);
		$this->store = new ScheduleFlowStore(
			$this->mapper,
			$this->createMock(FlowVersionService::class),
			$this->createMock(FlowService::class),
			$this->validator
		);
	}

	/**
	 * A compiled flow.
	 *
	 * @return CompiledScheduleFlow The flow.
	 */
	private function compiled(): CompiledScheduleFlow {
		$application = new ObjectEntity();
		$application->setUuid('app-1');

		return new CompiledScheduleFlow(
			new ScheduleRelease($application, [], 'pieter'),
			new ScheduleDescriptor('nightly', new ScheduleCadence(null, '0 3 * * *'), new ScheduleCall('a')),
			'0 3 * * *',
			['type' => 'x', 'config' => []]
		);
	}

	public function testACreateTheValidatorRefusesIsNotInserted(): void {
		$this->validator->expects($this->once())->method('validate')
			->willThrowException(new InvalidArgumentException('no'));
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->store->write($this->compiled(), null);
	}

	public function testAChangedGraphTheValidatorRefusesIsNotStored(): void {
		$this->validator->expects($this->once())->method('validate')
			->willThrowException(new InvalidArgumentException('no'));
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->store->write($this->compiled(), new Flow());
	}

	public function testAFlowNamedForTheSlugIsFound(): void {
		$flow = new Flow();
		$flow->setName('crm: nightly');
		$this->mapper->method('findAllFlows')->willReturn([$flow]);

		$this->assertTrue($this->store->hasFlowsNamedFor('crm'));
		$this->assertFalse($this->store->hasFlowsNamedFor('cr'));
	}
}
