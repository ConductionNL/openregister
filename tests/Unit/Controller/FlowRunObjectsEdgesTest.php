<?php

/**
 * FlowRunController::objects for the runs the golden test does not reach: a
 * run still suspended, a run that wrote nothing, and a caller with no session.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowRunController;
use OCA\OpenRegister\Db\AuditFlowAttribution;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowRunControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FlowRunObjectsEdgesTest extends TestCase {

	private FlowRunMapper&MockObject $mapper;

	private AuditFlowAttribution&MockObject $audits;

	protected function setUp(): void {
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->audits = $this->createMock(AuditFlowAttribution::class);
	}

	private function controller(?string $uid): FlowRunController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);

		return FlowRunControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			mapper: $this->mapper,
			runner: $this->createMock(FlowRunService::class),
			resolvers: $this->createMock(FlowLocator::class),
			userSession: $session,
			organisationService: $this->createMock(OrganisationService::class),
			guard: new FlowRunnableGuard(),
			groupManager: $groups,
			auditTrails: $this->audits
		);
	}

	private static function aRun(string $status): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-9');
		$run->setStatus($status);
		return $run;
	}

	public function testASuspendedRunReportsWhatItTouchedSoFar(): void {
		$row = new AuditTrail();
		$row->setUuid('a-1');
		$row->setFlowNode('raise-task');
		$row->setFlowStep(1);
		$row->setObjectUuid('obj-1');
		$row->setRegister(3);
		$row->setSchema(7);
		$row->setAction('create');
		$this->mapper->method('findByUuidVisibleTo')->willReturn(self::aRun('suspended'));
		$this->audits->expects($this->once())->method('findByRun')->with('run-1')->willReturn([$row]);

		$response = $this->controller('alice')->objects('run-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'run' => 'run-1',
				'flowId' => 'flow-9',
				'nodes' => [
					[
						'node' => 'raise-task',
						'step' => 1,
						'objects' => [
							['objectUuid' => 'obj-1', 'register' => 3, 'schema' => 7, 'action' => 'create', 'step' => 1, 'created' => null, 'auditUuid' => 'a-1'],
						],
					],
				],
			],
			$response->getData()
		);
	}

	public function testARunThatWroteNothingIsAnEmptyListNotAnError(): void {
		$this->mapper->method('findByUuidVisibleTo')->willReturn(self::aRun('completed'));
		$this->audits->method('findByRun')->willReturn([]);

		$response = $this->controller('alice')->objects('run-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['run' => 'run-1', 'flowId' => 'flow-9', 'nodes' => []], $response->getData());
	}

	public function testACallerWithoutASessionLearnsNothing(): void {
		$this->mapper->expects($this->never())->method('findByUuidVisibleTo');
		$this->audits->expects($this->never())->method('findByRun');

		$response = $this->controller(null)->objects('run-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such run'], $response->getData());
	}
}//end class
