<?php

/**
 * A control that was declared where nothing reads it, now decided where the
 * run path actually looks.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-FRH-001 and REQ-FRH-002.
 */
class FlowRunAuthorizationTest extends TestCase {

	/**
	 * A flow owned by somebody.
	 *
	 * @param string|null $owner The owner uid, or null for an unadopted flow.
	 *
	 * @return Flow The flow.
	 */
	private function flow(?string $owner = 'anja'): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setOwner($owner);
		$flow->setEnabled(true);

		return $flow;
	}//end flow()

	/**
	 * An access double for one caller.
	 *
	 * @param string|null $uid     The signed-in uid, or null for none.
	 * @param bool        $isAdmin Whether they are an administrator.
	 * @param bool        $mayEdit Whether they hold `flow.update`.
	 *
	 * @return FlowAccess The double.
	 */
	private function access(?string $uid, bool $isAdmin = false, bool $mayEdit = false): FlowAccess {
		$access = $this->createMock(FlowAccess::class);

		if ($uid === null) {
			$access->method('currentUser')->willReturn(null);
		}

		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$access->method('currentUser')->willReturn($user);
		}

		$access->method('callerIsAdmin')->willReturn($isAdmin);
		$access->method('may')->willReturn($mayEdit);

		return $access;
	}//end access()

	/**
	 * 🔴 The least privileged principal that should be refused: an ordinary
	 * signed-in colleague, in the same organisation, who neither owns the flow
	 * nor holds `flow.update`.
	 *
	 * Before this, the organisation check was the whole of it — and on the
	 * single-organisation instance that is the common case, that check passes
	 * for every signed-in account.
	 *
	 * @return void
	 */
	public function testAColleagueWhoNeitherOwnsItNorMayEditIsRefused(): void {
		$authorization = new FlowRunAuthorization(access: $this->access(uid: 'bram'));

		$this->assertSame(
			FlowRunAuthorization::NOT_YOURS,
			$authorization->verdictFor(flow: $this->flow()),
			'a signed-in colleague could run any flow in the organisation, including one they may not edit'
		);
		$this->assertFalse($authorization->mayRun(flow: $this->flow()));
	}//end testAColleagueWhoNeitherOwnsItNorMayEditIsRefused()

	/**
	 * The control: the OWNER may run their own flow, holding no editing right.
	 *
	 * Without this, the refusal above could be passing on a resolver that
	 * refuses everybody.
	 *
	 * @return void
	 */
	public function testTheOwnerMayRunTheirOwnFlow(): void {
		$authorization = new FlowRunAuthorization(access: $this->access(uid: 'anja'));

		$this->assertTrue(
			$authorization->mayRun(flow: $this->flow(owner: 'anja')),
			'the control: a flow answers to its owner, which is what the declaration always implied'
		);
	}//end testTheOwnerMayRunTheirOwnFlow()

	/**
	 * The second control: a holder of `flow.update` may run somebody else's.
	 *
	 * @return void
	 */
	public function testAHolderOfTheEditingRightMayRunSomebodyElsesFlow(): void {
		$authorization = new FlowRunAuthorization(access: $this->access(uid: 'bram', mayEdit: true));

		$this->assertTrue(
			$authorization->mayRun(flow: $this->flow(owner: 'anja')),
			'the right an administrator can narrow is the bar for running a flow that is not yours'
		);
	}//end testAHolderOfTheEditingRightMayRunSomebodyElsesFlow()

	/**
	 * The third control: an administrator may.
	 *
	 * @return void
	 */
	public function testAnAdministratorMay(): void {
		$authorization = new FlowRunAuthorization(access: $this->access(uid: 'beheerder', isAdmin: true));

		$this->assertTrue($authorization->mayRun(flow: $this->flow(owner: 'anja')));
	}//end testAnAdministratorMay()

	/**
	 * 🔴 An unowned flow is refused to EVERYONE, the administrator included.
	 *
	 * The engine will not dispatch one either, so letting anybody through
	 * would hand them a run that can only fail — and on `test()`, which
	 * executes synchronously, a run of a flow nobody has taken responsibility
	 * for.
	 *
	 * @return void
	 */
	public function testAnUnownedFlowIsRefusedToEveryone(): void {
		$unowned = $this->flow(owner: null);

		foreach (
			[
				'colleague' => $this->access(uid: 'bram'),
				'editor' => $this->access(uid: 'bram', mayEdit: true),
				'administrator' => $this->access(uid: 'beheerder', isAdmin: true),
			] as $who => $access
		) {
			$this->assertSame(
				FlowRunAuthorization::NO_OWNER,
				(new FlowRunAuthorization(access: $access))->verdictFor(flow: $unowned),
				sprintf('an unadopted flow must be refused to the %s too', $who)
			);
		}

		// And the engine agrees, which is why the door may refuse it.
		$this->assertFalse($unowned->canDispatch(), 'the door and the engine must not disagree about an unowned flow');
	}//end testAnUnownedFlowIsRefusedToEveryone()

	/**
	 * An empty owner string is an unowned flow, not a match for an empty uid.
	 *
	 * @return void
	 */
	public function testAnEmptyOwnerStringIsUnownedRatherThanAMatch(): void {
		$this->assertSame(
			FlowRunAuthorization::NO_OWNER,
			(new FlowRunAuthorization(access: $this->access(uid: '')))->verdictFor(flow: $this->flow(owner: '  '))
		);
	}//end testAnEmptyOwnerStringIsUnownedRatherThanAMatch()

	/**
	 * 🔴 No session and no collaborator both REFUSE.
	 *
	 * @return void
	 */
	public function testItFailsClosed(): void {
		$this->assertSame(
			FlowRunAuthorization::NO_SESSION,
			(new FlowRunAuthorization(access: $this->access(uid: null)))->verdictFor(flow: $this->flow())
		);

		$this->assertSame(
			FlowRunAuthorization::UNDECIDABLE,
			(new FlowRunAuthorization())->verdictFor(flow: $this->flow()),
			'no way to decide is a refusal, never an allow'
		);

		$this->assertSame(
			FlowRunAuthorization::UNDECIDABLE,
			(new FlowRunAuthorization(access: $this->access(uid: 'anja')))->verdictFor(flow: null)
		);
	}//end testItFailsClosed()

	/**
	 * The four refusals carry four different sentences.
	 *
	 * Collapsing them to one would send "sign in", "nobody owns this yet" and
	 * "this is not yours" to the same place.
	 *
	 * @return void
	 */
	public function testEachRefusalCarriesItsOwnSentence(): void {
		$authorization = new FlowRunAuthorization();

		$messages = [];
		foreach (
			[
				FlowRunAuthorization::NO_SESSION,
				FlowRunAuthorization::NO_OWNER,
				FlowRunAuthorization::NOT_YOURS,
				FlowRunAuthorization::UNDECIDABLE,
			] as $verdict
		) {
			$message = $authorization->messageFor(verdict: $verdict);
			$this->assertNotSame('', $message, $verdict . ' must say something');
			$messages[] = $message;
		}

		$this->assertSame($messages, array_unique($messages), 'four refusals, four sentences');
		$this->assertSame('', $authorization->messageFor(verdict: FlowRunAuthorization::ALLOWED));
	}//end testEachRefusalCarriesItsOwnSentence()

	/**
	 * 🔴 Every run path consults the resolver — asserted structurally, so a
	 * path added later fails here and is named.
	 *
	 * @return void
	 */
	public function testEveryRunPathConsultsTheResolver(): void {
		$lib = dirname(__DIR__, 4) . '/lib';

		$paths = [
			'FlowService::run() — which FlowController::run() and ObjectActionsController call'
				=> '/Service/Flow/FlowService.php',
			'FlowRunController::retry()/resume(), FlowRunMigrationController and '
			. 'FlowTestRunController, all through FlowRunnableGuard::refusalUnlessRunnable()'
				=> '/Service/Flow/FlowRunnableGuard.php',
			'FlowMcpToolProvider::runFlow(), through its own assertRunnable()'
				=> '/Mcp/BuiltIn/FlowMcpToolProvider.php',
		];

		// The three endpoints that used to hold the check inline now reach it
		// through the guard, so each of them is asserted to CALL the guard. A
		// controller that stops calling it is the same regression as one that
		// stops calling `assertRunnable` directly.
		$callers = [
			'FlowRunController::retry()/resume()' => '/Controller/FlowRunController.php',
			'FlowRunMigrationController' => '/Controller/FlowRunMigrationController.php',
			'FlowTestRunController::test()' => '/Controller/FlowTestRunController.php',
		];

		foreach ($callers as $what => $file) {
			$this->assertStringContainsString(
				'refusalUnlessRunnable',
				(string)file_get_contents($lib . $file),
				sprintf('%s no longer asks who may run this flow.', $what)
			);
		}

		foreach ($paths as $what => $file) {
			$source = (string)file_get_contents($lib . $file);
			$this->assertStringContainsString(
				'assertRunnable',
				$source,
				sprintf('%s no longer asks who may run this flow.', $what)
			);
		}
	}//end testEveryRunPathConsultsTheResolver()

	/**
	 * 🔴 The declaration says which store it governs.
	 *
	 * A reader of `flow_register.json` took `scope: private` to mean a flow
	 * answers to its owner. It did not, for any run. The file must not tell
	 * them something untrue.
	 *
	 * @return void
	 */
	public function testTheDescriptorSaysWhatItGoverns(): void {
		$descriptor = (string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/flow_register.json');

		$this->assertStringContainsString(
			'FlowRunAuthorization',
			$descriptor,
			'the declaration must name what actually governs a run, or a reader believes it does'
		);
		$this->assertStringContainsString('openregister_flows', $descriptor);
	}//end testTheDescriptorSaysWhatItGoverns()
}//end class
