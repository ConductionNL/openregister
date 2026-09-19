<?php

/**
 * Regression test for or#3643 — the unguarded flow-run endpoint.
 *
 * Deliberately independent of the FlowAccess collaborator added to
 * FlowRunControllerTest.php's setUp(): this file constructs the controller
 * with none of the new machinery, exactly as any pre-existing caller (a
 * migration, a factory, a test in another file) would, to prove the fix
 * closes the hole even for a construction site that never learns about
 * `$access` at all. The scenario is the exact one that was exploitable: an
 * ordinary signed-in user, a member of the flow's organisation but not its
 * owner and holding no special right, driving `test()` to completion on
 * somebody else's flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowRunController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class FlowRunTestSameOrgNonOwnerRegressionTest extends TestCase {

	public function testAnUnprivilegedSameOrgCallerCannotDriveATestRun(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => match ($key) {
				'flowId' => 'somebody-elses-flow',
				'pins' => [],
				default => $default,
			}
		);

		// "mallory" is a real, ordinary, signed-in user. She does not own the
		// flow, has no admin bit, no special group -- just an account on the
		// same Nextcloud instance / same organisation as the flow's owner,
		// which on a typical single-organisation install is EVERY signed-in
		// account.
		$mallory = $this->createMock(IUser::class);
		$mallory->method('getUID')->willReturn('mallory');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($mallory);

		$organisations = $this->createMock(OrganisationService::class);
		$org = new Organisation();
		$org->setUuid('org-1');
		$organisations->method('getActiveOrganisation')->willReturn($org);

		// The flow belongs to the SAME organisation (so FlowService::find()'s
		// scoping check passes) but its owner is "alice", not "mallory" --
		// mallory has no editing relationship to it whatsoever.
		$flow = new Flow();
		$flow->setUuid('somebody-elses-flow');
		$flow->setOrganisation('org-1');
		$flow->setOwner('alice');

		$flows = $this->createMock(FlowService::class);
		$flows->method('find')->willReturn($flow);

		$resolvers = $this->createMock(FlowLocator::class);
		$resolvers->method('resolveFlow')->willReturn(['id' => 'somebody-elses-flow', 'edges' => []]);

		$runner = $this->createMock(FlowRunService::class);
		$queued = new FlowRun();
		$queued->setStatus(FlowRun::STATUS_QUEUED);
		$runner->method('queue')->willReturn($queued);

		$done = new FlowRun();
		$done->setStatus(FlowRun::STATUS_COMPLETED);
		$runner->method('execute')->willReturn($done);

		// The load-bearing assertion: the engine must NEVER be reached by a
		// caller who may not edit this flow. Pre-fix, this fires once --
		// mallory's flow actually ran. Post-fix, it never fires.
		$runner->expects($this->never())->method('queue');

		$mapper = $this->createMock(FlowRunMapper::class);

		$controller = new FlowRunController(
			appName: 'openregister',
			request: $request,
			mapper: $mapper,
			runner: $runner,
			resolvers: $resolvers,
			userSession: $userSession,
			organisationService: $organisations,
			flows: $flows
		);

		$response = $controller->test();

		// Before the fix this was 200 -- mallory ran alice's flow. Fails
		// closed now: with no $access collaborator supplied at all, the
		// refusal is a 403 rather than a silent allow.
		$this->assertNotSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'an ordinary same-organisation, non-owner caller must NOT be able to run this flow'
		);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnUnprivilegedSameOrgCallerCannotDriveATestRun()

}//end class
