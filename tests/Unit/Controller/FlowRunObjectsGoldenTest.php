<?php

/**
 * Golden test pinning FlowRunController::objects: who may read a run's touched
 * objects, and the exact grouped answer.
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
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use DateTimeZone;
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
use PHPUnit\Framework\TestCase;

final class FlowRunObjectsGoldenTest extends TestCase {

	private array $auditReads = [];

	private function controller(?FlowRun $visible, bool $withAudits): FlowRunController {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findByUuidVisibleTo')->willReturn($visible);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$audits = null;
		if ($withAudits === true) {
			$audits = $this->createMock(AuditFlowAttribution::class);
			$audits->method('findByRun')->willReturnCallback(
				function (string $runUuid): array {
					$this->auditReads[] = $runUuid;
					return self::rows();
				}
			);
		}

		return FlowRunControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			mapper: $mapper,
			runner: $this->createMock(FlowRunService::class),
			resolvers: $this->createMock(FlowLocator::class),
			userSession: $session,
			organisationService: $this->createMock(OrganisationService::class),
			guard: new FlowRunnableGuard(),
			groupManager: $this->groupManager(),
			auditTrails: $audits
		);
	}

	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);
		return $groups;
	}

	private static function row(string $uuid, string $node, int $step, string $object, ?string $created): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid($uuid);
		$row->setFlowNode($node);
		$row->setFlowStep($step);
		$row->setObjectUuid($object);
		$row->setRegister(3);
		$row->setSchema(7);
		$row->setAction('update');
		if ($created !== null) {
			$row->setCreated(new DateTime($created, new DateTimeZone('UTC')));
		}

		return $row;
	}

	private static function rows(): array {
		return [
			self::row('a-1', 'notify', 3, 'obj-1', '2026-09-07T10:00:03+00:00'),
			self::row('a-2', 'raise-task', 1, 'obj-2', '2026-09-07T10:00:01+00:00'),
			self::row('a-3', 'notify', 2, 'obj-3', null),
		];
	}

	private static function aRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-9');
		return $run;
	}

	public function testAnInvisibleRunIsNotFoundBeforeTheAuditIsRead(): void {
		$response = $this->controller(null, true)->objects('run-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such run'], $response->getData());
		$this->assertSame([], $this->auditReads);
	}

	public function testAMissingAuditTrailIsUnavailable(): void {
		$response = $this->controller(self::aRun(), false)->objects('run-1');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'Audit trail unavailable'], $response->getData());
	}

	public function testTheTouchedObjectsAreGroupedPerNodeInStepOrder(): void {
		$response = $this->controller(self::aRun(), true)->objects('run-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['run-1'], $this->auditReads);
		$this->assertSame(
			[
				'run' => 'run-1',
				'flowId' => 'flow-9',
				'nodes' => [
					[
						'node' => 'raise-task',
						'step' => 1,
						'objects' => [
							['objectUuid' => 'obj-2', 'register' => 3, 'schema' => 7, 'action' => 'update', 'step' => 1, 'created' => '2026-09-07T10:00:01+00:00', 'auditUuid' => 'a-2'],
						],
					],
					[
						'node' => 'notify',
						'step' => 3,
						'objects' => [
							['objectUuid' => 'obj-1', 'register' => 3, 'schema' => 7, 'action' => 'update', 'step' => 3, 'created' => '2026-09-07T10:00:03+00:00', 'auditUuid' => 'a-1'],
							['objectUuid' => 'obj-3', 'register' => 3, 'schema' => 7, 'action' => 'update', 'step' => 2, 'created' => null, 'auditUuid' => 'a-3'],
						],
					],
				],
			],
			$response->getData()
		);
	}
}//end class
