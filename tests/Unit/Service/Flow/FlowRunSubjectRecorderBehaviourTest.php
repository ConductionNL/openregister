<?php

/**
 * FlowRunSubjectRecorder: when a claim is recorded and stored, the failures it
 * absorbs, the one-object rule of recordOne, how the run is located, and the
 * anchor a task attaches to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\FlowRunSubjects;
use OCA\OpenRegister\Service\Flow\FlowSubjectCandidates;
use OCA\OpenRegister\Service\Flow\FlowSubjectClaim;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunSubjects
 * @uses \OCA\OpenRegister\Service\Flow\FlowSubjectCandidates
 * @uses \OCA\OpenRegister\Service\Flow\FlowSubjectClaim
 */
class FlowRunSubjectRecorderBehaviourTest extends TestCase {

	private FlowRunMapperInterface&MockObject $runs;

	private LoggerInterface&MockObject $logger;

	private FlowRun $run;

	private FlowRunSubjectRecorder $recorder;

	protected function setUp(): void {
		parent::setUp();
		$this->run = new FlowRun();
		$this->run->setUuid('run-1');
		$this->runs = $this->createMock(FlowRunMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->recorder = new FlowRunSubjectRecorder(runs: $this->runs, subjects: new FlowRunSubjects(), logger: $this->logger);
	}//end setUp()

	private function found(): void {
		$this->runs->method('findByUuid')->with('run-1')->willReturn($this->run);
	}//end found()

	public function testAClaimWithoutAnObjectIsNotRecorded(): void {
		$this->runs->expects(self::never())->method('findByUuid');
		$this->runs->expects(self::never())->method('update');

		$this->recorder->record(context: [FlowRunContext::CONTEXT_RUN => 'run-1'], claim: new FlowSubjectClaim(role: 'zaak', uuid: '  '));
	}//end testAClaimWithoutAnObjectIsNotRecorded()

	public function testTheLegacyRunKeyLocatesTheRunAndTheClaimIsStored(): void {
		$this->found();
		$this->runs->expects(self::once())->method('update')->with($this->run);

		$this->recorder->record(context: ['runUuid' => ' run-1 '], claim: new FlowSubjectClaim(role: ' zaak ', uuid: 'obj-1', register: '3', schema: '7'));

		$stored = $this->run->getSubjects()['zaak'];
		self::assertSame(['obj-1', '3', '7'], [$stored['uuid'], $stored['register'], $stored['schema']]);
	}//end testTheLegacyRunKeyLocatesTheRunAndTheClaimIsStored()

	public function testAFailedStoreIsLoggedNotThrown(): void {
		$this->found();
		$this->runs->method('update')->willThrowException(new RuntimeException('lock wait timeout'));
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('Could not record "zaak" on run "run-1": lock wait timeout'));

		$this->recorder->record(context: [FlowRunContext::CONTEXT_RUN => 'run-1'], claim: new FlowSubjectClaim(role: 'zaak', uuid: 'obj-1'));

		self::assertSame('obj-1', $this->run->getSubjects()['zaak']['uuid'], 'the claim was applied before the store failed');
	}//end testAFailedStoreIsLoggedNotThrown()

	public function testAnUnreadableRunIsLoggedAndRecordsNothing(): void {
		$this->runs->method('findByUuid')->willThrowException(new RuntimeException('no such run'));
		$this->runs->expects(self::never())->method('update');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('Could not read run "run-9": no such run'));

		self::assertNull($this->recorder->runFor(context: [FlowRunContext::CONTEXT_RUN => 'run-9']));
	}//end testAnUnreadableRunIsLoggedAndRecordsNothing()

	public function testRecordOneRecordsTheSoleObject(): void {
		$this->found();
		$this->runs->expects(self::once())->method('update');

		$this->recorder->recordOne(
			context: [FlowRunContext::CONTEXT_RUN => 'run-1'],
			candidates: new FlowSubjectCandidates(role: 'zaak', uuids: ['obj-1'], register: '3', schema: '7')
		);

		self::assertSame('obj-1', $this->run->getSubjects()['zaak']['uuid']);
	}//end testRecordOneRecordsTheSoleObject()

	public function testRecordOneRefusesToGuessBetweenSeveralObjects(): void {
		$this->runs->expects(self::never())->method('findByUuid');
		$this->logger->expects(self::once())->method('info')
			->with(self::stringContains('A step on run "run-1" records its object as "zaak" but acted on 2 of them, so nothing was recorded.'));

		$this->recorder->recordOne(context: ['runUuid' => 'run-1'], candidates: new FlowSubjectCandidates(role: 'zaak', uuids: ['a', 'b']));
	}//end testRecordOneRefusesToGuessBetweenSeveralObjects()

	public function testRecordOneWithoutARoleDoesNothing(): void {
		$this->runs->expects(self::never())->method('findByUuid');
		$this->logger->expects(self::never())->method('info');

		$this->recorder->recordOne(context: ['runUuid' => 'run-1'], candidates: new FlowSubjectCandidates(role: ' ', uuids: ['a', 'b']));
	}//end testRecordOneWithoutARoleDoesNothing()

	public function testTheAnchorCarriesNumericIdsOnly(): void {
		$this->found();
		$this->run->setSubjects([
			'zaak' => ['uuid' => ' obj-1 ', 'register' => '3', 'schema' => '7'],
			'besluit' => ['uuid' => 'obj-2', 'register' => 'zaken', 'schema' => ''],
		]);

		self::assertSame(['objectUuid' => 'obj-1', 'registerId' => 3, 'schemaId' => 7], $this->recorder->anchorFor(context: ['runUuid' => 'run-1'], role: ' zaak '));
		self::assertSame(['objectUuid' => 'obj-2'], $this->recorder->anchorFor(context: ['runUuid' => 'run-1'], role: 'besluit'));
		self::assertSame([], $this->recorder->anchorFor(context: ['runUuid' => 'run-1'], role: ''));
	}//end testTheAnchorCarriesNumericIdsOnly()

	public function testAnAnchorWithoutARunRefuses(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('This step attaches its task to the subject "zaak", and its run could not be read');

		$this->recorder->anchorFor(context: [], role: 'zaak');
	}//end testAnAnchorWithoutARunRefuses()
	public function testAClaimOutsideARunIsDropped(): void {
		$this->runs->expects(self::never())->method('findByUuid');
		$this->runs->expects(self::never())->method('update');

		$this->recorder->record(context: ['runUuid' => '  '], claim: new FlowSubjectClaim(role: 'zaak', uuid: 'obj-1'));

		self::assertNull($this->run->getSubjects());
	}//end testAClaimOutsideARunIsDropped()
}//end class
