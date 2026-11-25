<?php

declare(strict_types=1);

/**
 * The public survey door: 404 that counts against a guesser, 410 that tells a respondent why, 201 on an answer.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SurveyAnswerController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;
use OCA\OpenRegister\Service\Survey\SurveyAnswering;
use OCA\OpenRegister\Service\Survey\SurveyAnswerRefusedException;
use OCA\OpenRegister\Service\Survey\SurveyAnswerVisit;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SurveyAnswerController over a mocked answering service.
 */
#[CoversClass(SurveyAnswerController::class)]
#[UsesClass(SurveyAnswerVisit::class)]
#[UsesClass(SurveyAnswerRefusedException::class)]
class SurveyAnswerControllerTest extends TestCase {

	/**
	 * The answering service.
	 *
	 * @var SurveyAnswering&MockObject
	 */
	private SurveyAnswering&MockObject $answering;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The controller under test.
	 */
	private SurveyAnswerController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->answering = $this->createMock(SurveyAnswering::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getRemoteAddress')->willReturn('192.0.2.1');
		$this->controller = new SurveyAnswerController(request: $this->request, answering: $this->answering);
	}//end setUp()

	/**
	 * A followed invitation.
	 *
	 * @return SurveyAnswerVisit The visit.
	 */
	private function visit(): SurveyAnswerVisit {
		return new SurveyAnswerVisit(link: $this->createMock(AccessLinkInterface::class), invitation: new ObjectEntity());
	}//end visit()

	/**
	 * A link that opens no invitation is 404 on both doors, throttled, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testALinkThatOpensNothingIsAThrottled404(): void {
		$this->answering->method('open')->willReturn(null);
		$this->answering->expects($this->never())->method('recordUse');
		$this->answering->expects($this->never())->method('answer');

		foreach ([$this->controller->show(anchor: 'x'), $this->controller->submit(anchor: 'x')] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertTrue($response->isThrottled());
			$this->assertSame(['action' => ObjectAccessLinksInterface::THROTTLE_ACTION], $response->getThrottleMetadata());
		}
	}//end testALinkThatOpensNothingIsAThrottled404()

	/**
	 * The form is served and its read recorded.
	 *
	 * @return void
	 */
	public function testTheFormIsServedAndTheReadRecorded(): void {
		$visit = $this->visit();
		$this->answering->method('open')->with('anchor')->willReturn($visit);
		$this->answering->method('form')->willReturn(['survey' => ['id' => 's-1'], 'questions' => []]);
		$this->answering->expects($this->once())->method('recordUse')
			->with($visit, ObjectAccessLinksInterface::ACT_READ, '192.0.2.1');

		$response = $this->controller->show(anchor: 'anchor');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['survey' => ['id' => 's-1'], 'questions' => []], $response->getData());
	}//end testTheFormIsServedAndTheReadRecorded()

	/**
	 * An invitation that can no longer be answered says why, with its status, and records no use.
	 *
	 * @return void
	 */
	public function testARefusalAnswersWithItsStatusAndReason(): void {
		$this->answering->method('open')->willReturn($this->visit());
		$this->answering->method('form')->willThrowException(
			new SurveyAnswerRefusedException(message: 'This invitation has expired.', status: SurveyAnswerRefusedException::GONE)
		);
		$this->answering->method('answer')->willThrowException(
			new SurveyAnswerRefusedException(message: 'This question still needs an answer: "Score?".', status: SurveyAnswerRefusedException::INCOMPLETE)
		);
		$this->answering->expects($this->never())->method('recordUse');
		$this->request->method('getParam')->with('answers', [])->willReturn([]);

		$shown = $this->controller->show(anchor: 'a');
		$this->assertSame(Http::STATUS_GONE, $shown->getStatus());
		$this->assertSame(['error' => 'This invitation has expired.'], $shown->getData());
		$this->assertFalse($shown->isThrottled(), 'a live link is not a guess');

		$submitted = $this->controller->submit(anchor: 'a');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $submitted->getStatus());
		$this->assertSame(['error' => 'This question still needs an answer: "Score?".'], $submitted->getData());
	}//end testARefusalAnswersWithItsStatusAndReason()

	/**
	 * Answers that are not a list are refused before the service sees them.
	 *
	 * @return void
	 */
	public function testAnswersThatAreNotAListAreABadRequest(): void {
		$this->answering->method('open')->willReturn($this->visit());
		$this->answering->expects($this->never())->method('answer');
		$this->request->method('getParam')->with('answers', [])->willReturn('5');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->submit(anchor: 'a')->getStatus());
	}//end testAnswersThatAreNotAListAreABadRequest()

	/**
	 * An accepted submission is 201 with the answer set, and recorded as answered.
	 *
	 * @return void
	 */
	public function testAnAcceptedSubmissionIsCreatedAndRecorded(): void {
		$visit = $this->visit();
		$answers = [['question' => 'score', 'value' => 5]];
		$this->answering->method('open')->willReturn($visit);
		$this->request->method('getParam')->with('answers', [])->willReturn($answers);
		$this->answering->expects($this->once())->method('answer')->with($visit, $answers)->willReturn(['id' => 'set-1']);
		$this->answering->expects($this->once())->method('recordUse')
			->with($visit, SurveyAnswering::ACT_ANSWERED, '192.0.2.1');

		$response = $this->controller->submit(anchor: 'a');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['answerSet' => 'set-1'], $response->getData());
	}//end testAnAcceptedSubmissionIsCreatedAndRecorded()
}//end class
