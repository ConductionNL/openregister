<?php

/**
 * Reading back what a run DECLARED, next to what it DID.
 *
 * 🔴 THE TWO ENDPOINTS ANSWER DIFFERENT QUESTIONS AND MUST KEEP DISAGREEING.
 * `/flow-runs/{uuid}` carries the DECLARED subject set: the objects the author
 * said this run is working with, each under a role. `/flow-runs/{uuid}/objects`
 * is DERIVED from the audit trail: everything the run happened to touch.
 *
 * A case flow READS a case it never writes, and WRITES a task and a document it
 * does not consider subjects. So the derived list is too much and too little at
 * once, and decisively it carries no ROLE — `attachTo: case` needs a name, and
 * a list of things that happened has none to give.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FlowRunController;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\AuditFlowAttribution;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the declared-subject read.
 *
 * @covers \OCA\OpenRegister\Controller\FlowRunController
 * @uses \OCA\OpenRegister\Db\FlowRun
 */
final class FlowRunSubjectsReadTest extends TestCase {

	/**
	 * The run the mapper serves, or null for "not visible".
	 *
	 * @var FlowRun|null
	 */
	private ?FlowRun $run = null;

	/**
	 * A controller over a run the caller may see.
	 *
	 * @param FlowRun|null              $run    The visible run.
	 * @param AuditFlowAttribution|null $audits The audit reader, when wired.
	 *
	 * @return FlowRunController The controller.
	 */
	private function controller(?FlowRun $run, ?AuditFlowAttribution $audits = null): FlowRunController {
		$this->run = $run;

		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findByUuidVisibleTo')->willReturnCallback(
			fn (): ?FlowRun => $this->run
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		return new FlowRunController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			mapper: $mapper,
			runner: $this->createMock(FlowRunService::class),
			resolvers: $this->createMock(FlowLocator::class),
			userSession: $session,
			organisationService: $this->createMock(OrganisationService::class),
			groupManager: $groups,
			auditTrails: $audits
		);
	}//end controller()

	/**
	 * A run that declared a case and locked it.
	 *
	 * @return FlowRun The run.
	 */
	private function aRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setSubjects(
			['case' => ['uuid' => 'obj-case', 'register' => '3', 'schema' => '7', 'recordedAt' => '2026-09-07T10:00:00+00:00']]
		);

		return $run;
	}//end aRun()

	/**
	 * The run read carries the declared subjects, by role.
	 *
	 * @return void
	 */
	public function testTheRunReadCarriesTheDeclaredSubjects(): void {
		$data = $this->controller($this->aRun())->show('run-1')->getData();

		$this->assertArrayHasKey('subjects', $data);
		$this->assertSame('obj-case', $data['subjects']['case']['uuid']);
	}//end testTheRunReadCarriesTheDeclaredSubjects()

	/**
	 * 🔑 A RUN THAT DECLARED NOTHING ANSWERS AN EMPTY SET, NOT A MISSING KEY.
	 *
	 * A reader that has to tell "declared nothing" apart from "this build
	 * predates subjects" ends up writing the fallback twice and getting it
	 * wrong once.
	 *
	 * @return void
	 */
	public function testARunThatDeclaredNothingAnswersAnEmptySet(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');

		$this->assertSame([], $this->controller($run)->show('run-1')->getData()['subjects']);
	}//end testARunThatDeclaredNothingAnswersAnEmptySet()

	/**
	 * A run nobody may see is a 404 on the subject read too.
	 *
	 * The declared set is as sensitive as the run itself: it names the records
	 * a flow is working on.
	 *
	 * @return void
	 */
	public function testAnInvisibleRunIsNotFound(): void {
		$this->assertSame(404, $this->controller(null)->show('run-1')->getStatus());
	}//end testAnInvisibleRunIsNotFound()

	/**
	 * 🔴 THE DECLARED SET AND THE DERIVED LIST DISAGREE, BY DESIGN.
	 *
	 * This run READ a case and never wrote it, and WROTE a task it does not
	 * consider a subject. So the case appears only in the declared set and the
	 * task only in the derived list. That is the whole reason the declared set
	 * exists: the audit answers the auditor, and only a declaration answers the
	 * author.
	 *
	 * @return void
	 */
	public function testAnObjectReadButNeverWrittenAppearsInOneListAndNotTheOther(): void {
		$written = new AuditTrail();
		$written->setUuid('audit-1');
		$written->setFlowNode('raise-task');
		$written->setFlowStep(1);
		$written->setObjectUuid('obj-task');
		$written->setAction('create');

		$audits = $this->createMock(AuditFlowAttribution::class);
		$audits->method('findByRun')->willReturn([$written]);

		$controller = $this->controller($this->aRun(), $audits);

		$declared = $controller->show('run-1')->getData()['subjects'];
		$this->assertSame('obj-case', $declared['case']['uuid']);

		$touched = json_encode($controller->objects('run-1')->getData());
		$this->assertStringContainsString('obj-task', $touched, 'the audit shows what the run wrote');
		$this->assertStringNotContainsString(
			'obj-case',
			$touched,
			'a case the run only READ never reaches the audit-derived list'
		);
	}//end testAnObjectReadButNeverWrittenAppearsInOneListAndNotTheOther()
}//end class
