<?php

/**
 * What the retention route reports must be what the destruction job will do.
 *
 * `RetentionController::approveDestructionList()` is a second, fully
 * independent approval implementation: it filters excluded uuids itself,
 * records the approval, sets `status = 'approved'` and queues
 * DestructionExecutionJob directly. It never calls
 * `DestructionService::approveList()`.
 *
 * The records were always safe on this route, because the job refuses an entry
 * a reviewer decided to retain or transfer. The COUNTS were not: the audit
 * trail and the 200 response are both computed from the list before the job
 * runs, and nothing corrects them afterwards. So the approval record and the
 * destruction certificate stated a number of destroyed objects that never
 * happened — on a statutory records-management path, where that document is
 * the entire point.
 *
 * A real DestructionService is wired in rather than a mock, so these assert the
 * whole chain rather than that one collaborator was called.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Controller\RetentionController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RetentionControllerApprovalCountTest extends TestCase {

	private AuditTrailMapper&MockObject $auditMapper;

	private ObjectEntity $listObject;

	/**
	 * A destruction list holding one entry per decision kind.
	 *
	 * @return array<string, mixed> The list.
	 */
	private function listWithDecisions(): array {
		return [
			'status' => 'in_review',
			'objectCount' => 3,
			'objects' => [
				['uuid' => 'keep-me', 'schema' => null, 'decision' => DestructionReviewService::ANSWER_RETAIN],
				['uuid' => 'move-me', 'schema' => null, 'decision' => DestructionReviewService::ANSWER_TRANSFER],
				['uuid' => 'destroy-me', 'schema' => null, 'decision' => 'destroy'],
			],
			'decisions' => [
				[
					'entry' => 'keep-me',
					'answer' => DestructionReviewService::ANSWER_RETAIN,
					'reason' => 'Still needed for the ongoing objection.',
				],
			],
			'approvals' => [],
		];
	}

	private function controller(): RetentionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivist');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->listObject = new ObjectEntity();
		$this->listObject->setObject($this->listWithDecisions());

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('find')->willReturn($this->listObject);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn([]);

		$this->auditMapper = $this->createMock(AuditTrailMapper::class);

		return new RetentionController(
			'openregister',
			$request,
			$this->createMock(RetentionService::class),
			$this->createMock(ObjectRetentionHandler::class),
			$this->createMock(SaveObject::class),
			$objectMapper,
			$this->createMock(SchemaMapper::class),
			$this->auditMapper,
			new DestructionService(
				objectMapper: $objectMapper,
				appConfig: $this->createMock(IAppConfig::class),
				jobList: $this->createMock(IJobList::class),
				userSession: $session,
				logger: new NullLogger(),
			),
			$this->createMock(IJobList::class),
			$session,
			new NullLogger()
		);
	}

	public function testTheResponseCountsOnlyWhatWillActuallyBeDestroyed(): void {
		// THE REGRESSION TEST. This reported 3 — the two entries the job refuses
		// included — while exactly one object was ever going to be destroyed.
		$response = $this->controller()->approveDestructionList('list-uuid');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('approved', $response->getData()['status']);
		$this->assertSame(1, $response->getData()['objectCount']);
	}

	public function testTheAuditTrailRecordsTheSameCountAsTheResponse(): void {
		// The approval record and the destruction certificate are read later, by
		// someone who cannot re-derive the number. They must not disagree.
		$controller = $this->controller();

		$action = null;
		$recorded = null;
		$this->auditMapper->method('createAuditTrailEntry')
			->willReturnCallback(
				function ($object, $entryAction, $payload) use (&$action, &$recorded) {
					$action = $entryAction;
					$recorded = $payload;
					return new AuditTrail();
				}
			);

		$response = $controller->approveDestructionList('list-uuid');

		$this->assertSame('archival.destruction_approved', $action);
		$this->assertSame(1, $recorded['objectCount']);
		$this->assertSame($response->getData()['objectCount'], $recorded['objectCount']);
	}

	public function testTheWithheldEntriesAreKeptOnTheListRatherThanDropped(): void {
		// Withholding is not deletion: the retained and transferred records move
		// to excludedObjects so the certificate can say what was spared and why.
		$this->controller()->approveDestructionList('list-uuid');

		$stored = $this->listObject->getObject();
		$excluded = array_map(
			static fn (array $e): string => (string)($e['uuid'] ?? ''),
			$stored['excludedObjects']
		);

		$this->assertSame(['destroy-me'], array_column($stored['objects'], 'uuid'));
		$this->assertEqualsCanonicalizing(['keep-me', 'move-me'], $excluded);
	}
}//end class
