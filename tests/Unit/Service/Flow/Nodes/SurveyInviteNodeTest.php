<?php

declare(strict_types=1);

/**
 * The "Invite to a survey" step: the delay suspends the run, the resume invites, and each item carries its outcome.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\SurveyInviteNode;
use OCA\OpenRegister\Service\Survey\SurveyInvitationRequest;
use OCA\OpenRegister\Service\Survey\SurveyInvitations;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * SurveyInviteNode over a mocked invitation service.
 */
#[CoversClass(SurveyInviteNode::class)]
#[UsesClass(SurveyInvitationRequest::class)]
#[UsesClass(FlowNodeCall::class)]
class SurveyInviteNodeTest extends TestCase {

	/**
	 * The invitation service.
	 *
	 * @var SurveyInvitations&MockObject
	 */
	private SurveyInvitations&MockObject $invitations;

	/**
	 * The node under test.
	 */
	private SurveyInviteNode $node;

	/**
	 * The step config the tests run.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'surveys', 'survey' => 's-1', 'respondent' => '{{ email }}'];

	/**
	 * Wire the node.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => 'https://cloud.example/'.$route.'/'.$parameters['anchor']
		);

		$this->invitations = $this->createMock(SurveyInvitations::class);
		$this->node = new SurveyInviteNode(l10n: $l10n, urls: $urls, invitations: $this->invitations);
	}//end setUp()

	/**
	 * The step is a send task in the messaging palette, under its own id.
	 *
	 * @return void
	 */
	public function testTheStepIsAMessagingSendTask(): void {
		$this->assertSame('openregister.survey-invite', $this->node->getId());
		$this->assertSame(IFlowNodeTaxonomy::KIND_SEND_TASK, $this->node->getKind());
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_MESSAGING, $this->node->getCategory());
		$this->assertSame(array_column($this->node->configForm(), 'key'), $this->node->configKeys());
	}//end testTheStepIsAMessagingSendTask()

	/**
	 * A step missing a required key, or with a duration that does not read, is refused; a template passes.
	 *
	 * @return void
	 */
	public function testTheConfigNeedsItsKeysAndReadableDurations(): void {
		$this->node->validateConfig(config: $this->config + ['delay' => '3 days', 'expiresIn' => '{{ window }}']);

		foreach ([['survey' => ''], ['delay' => 'whenever'], ['expiresIn' => 'yesterday']] as $broken) {
			try {
				$this->node->validateConfig(config: array_merge($this->config, $broken));
				$this->fail('Accepted '.json_encode($broken));
			} catch (UnexpectedValueException $refused) {
				$this->assertNotSame('', $refused->getMessage());
			}
		}
	}//end testTheConfigNeedsItsKeysAndReadableDurations()

	/**
	 * With a delay, the first call suspends the run about that far ahead and invites nobody.
	 *
	 * @return void
	 */
	public function testADelaySuspendsTheRunBeforeAnyInvitation(): void {
		$this->invitations->expects($this->never())->method('invite');

		try {
			$this->node->execute(call: new FlowNodeCall(items: [['email' => 'jan@example.org']], config: $this->config + ['delay' => '3 days']));
			$this->fail('The run was not suspended.');
		} catch (FlowSuspension $suspended) {
			$this->assertStringContainsString('inviting to the survey at', $suspended->getMessage());
			$this->assertStringContainsString(date('Y-m-d', strtotime('+3 days')), $suspended->getMessage());
		}
	}//end testADelaySuspendsTheRunBeforeAnyInvitation()

	/**
	 * On the resume each item is invited, as the run's owner, about its own object, and carries the answering url.
	 *
	 * @return void
	 */
	public function testTheResumeInvitesEachItemAndPutsTheOutcomeOnIt(): void {
		$requests = [];
		$this->invitations->expects($this->exactly(2))->method('invite')->willReturnCallback(
			function (SurveyInvitationRequest $request) use (&$requests): array {
				$requests[] = $request;
				if ($request->respondent === 'piet@example.org') {
					return ['invitation' => ['id' => 'inv-2', 'state' => 'blocked'], 'blocked' => true, 'reason' => 'too often', 'anchor' => null];
				}

				return ['invitation' => ['id' => 'inv-1', 'state' => 'sent'], 'blocked' => false, 'reason' => '', 'anchor' => 'Anchor1'];
			}
		);

		$out = $this->node->execute(call: new FlowNodeCall(
			items: [
				[FlowItems::JSON => ['id' => 'case-1', 'email' => 'jan@example.org']],
				['uuid' => 'case-2', 'email' => 'piet@example.org'],
			],
			config: $this->config + ['delay' => '3 days'],
			context: ['resuming' => true, 'triggeredBy' => 'owner']
		));

		$this->assertSame('case-1', $requests[0]->subjectObject);
		$this->assertSame('jan@example.org', $requests[0]->respondent);
		$this->assertSame('owner', $requests[0]->minter);
		$this->assertSame('surveys', $requests[0]->register);
		$this->assertSame('case-2', $requests[1]->subjectObject);
		// No expiresIn: thirty days from now.
		$this->assertSame(date('Y-m-d', strtotime('+30 days')), substr($requests[0]->expiresAt, 0, 10));

		$this->assertSame(
			['id' => 'inv-1', 'state' => 'sent', 'blocked' => false, 'reason' => '', 'url' => 'https://cloud.example/openregister.surveyAnswer.show/Anchor1'],
			$out[0][FlowItems::JSON][SurveyInviteNode::OUTPUT_KEY]
		);
		$this->assertSame(['id' => 'inv-2', 'state' => 'blocked', 'blocked' => true, 'reason' => 'too often'], $out[1][SurveyInviteNode::OUTPUT_KEY]);
		$this->assertSame('piet@example.org', $out[1]['email'], 'the item is kept');
	}//end testTheResumeInvitesEachItemAndPutsTheOutcomeOnIt()

	/**
	 * An item the invitation refuses carries the reason, and the other items are still invited.
	 *
	 * @return void
	 */
	public function testARefusedItemCarriesItsReason(): void {
		$this->invitations->method('invite')->willThrowException(new InvalidArgumentException('A survey invitation needs a respondent address.'));

		$out = $this->node->execute(call: new FlowNodeCall(items: [['id' => 'case-1']], config: $this->config + ['subject' => 'case-9']));

		$this->assertSame(['error' => 'A survey invitation needs a respondent address.'], $out[0][SurveyInviteNode::OUTPUT_KEY]);
	}//end testARefusedItemCarriesItsReason()

	/**
	 * No items, no invitations.
	 *
	 * @return void
	 */
	public function testNoItemsInvitesNobody(): void {
		$this->invitations->expects($this->never())->method('invite');

		$this->assertSame([], $this->node->execute(call: new FlowNodeCall(items: [], config: $this->config + ['delay' => '3 days'])));
	}//end testNoItemsInvitesNobody()
}//end class
