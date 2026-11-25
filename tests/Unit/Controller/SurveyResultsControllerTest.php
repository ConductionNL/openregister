<?php

/**
 * Tests for SurveyResultsController: the refusals, the results and the CSV export.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SurveyResultsController;
use OCA\OpenRegister\Service\Survey\SurveyAnswerTable;
use OCA\OpenRegister\Service\Survey\SurveyResults;
use OCA\OpenRegister\Service\Survey\SurveyResultsWithheldException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who gets what from a survey's results and export routes.
 *
 * @covers \OCA\OpenRegister\Controller\SurveyResultsController
 * @uses   \OCA\OpenRegister\Kernel\Contracts\CsvCell
 */
class SurveyResultsControllerTest extends TestCase {

	/**
	 * The results service double.
	 *
	 * @var SurveyResults&MockObject
	 */
	private SurveyResults&MockObject $results;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The controller under test.
	 *
	 * @var SurveyResultsController
	 */
	private SurveyResultsController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->results = $this->createMock(SurveyResults::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->controller = new SurveyResultsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->results,
			$this->session,
			$this->groups
		);
	}//end setUp()

	/**
	 * Sign a user in with these groups.
	 *
	 * @param list<string> $groupIds The user's groups.
	 *
	 * @return void
	 */
	private function signIn(array $groupIds): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reader');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('getUserGroupIds')->willReturn($groupIds);
		$this->groups->method('isAdmin')->willReturn(false);
	}//end signIn()

	/**
	 * An anonymous caller gets 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIs401(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->results->expects($this->never())->method('survey');

		$this->assertSame(401, $this->controller->results('surveys', 's-1')->getStatus());
		$this->assertSame(401, $this->controller->export('surveys', 's-1')->getStatus());
	}//end testWithoutASessionTheAnswerIs401()

	/**
	 * A survey the register does not hold is 404, whether missing or not found by the table.
	 *
	 * @return void
	 */
	public function testAMissingSurveyIs404(): void {
		$this->signIn(groupIds: ['quality']);
		$this->results->method('survey')->willReturnOnConsecutiveCalls(null, $this->throwException(new DoesNotExistException('gone')));

		$this->assertSame(404, $this->controller->results('surveys', 's-1')->getStatus());
		$this->assertSame(404, $this->controller->results('surveys', 's-2')->getStatus());
	}//end testAMissingSurveyIs404()

	/**
	 * A reader outside the survey's roles is refused, and no answers are read.
	 *
	 * @return void
	 */
	public function testAReaderOutsideTheRolesIsRefused(): void {
		$this->signIn(groupIds: ['staff']);
		$this->results->method('survey')->willReturn(['id' => 's-1', 'readerRoles' => ['quality']]);
		$this->results->method('mayRead')->with(['id' => 's-1', 'readerRoles' => ['quality']], ['staff'], false)->willReturn(false);
		$this->results->expects($this->never())->method('results');
		$this->results->expects($this->never())->method('exportTable');

		$this->assertSame(403, $this->controller->results('surveys', 's-1')->getStatus());
		$this->assertSame(403, $this->controller->export('surveys', 's-1')->getStatus());
	}//end testAReaderOutsideTheRolesIsRefused()

	/**
	 * A reader gets the results as the service gives them, withheld included.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheResults(): void {
		$this->signIn(groupIds: ['quality']);
		$this->results->method('survey')->willReturn(['id' => 's-1']);
		$this->results->method('mayRead')->willReturn(true);
		$body = ['survey' => 's-1', 'withheld' => true, 'count' => 2, 'reason' => 'too few', 'answerSets' => []];
		$this->results->method('results')->with('surveys', ['id' => 's-1'])->willReturn($body);

		$response = $this->controller->results('surveys', 's-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end testAReaderGetsTheResults()

	/**
	 * A withheld export is refused with 403, the count and the reason.
	 *
	 * @return void
	 */
	public function testAWithheldExportIsRefusedWithTheCountAndReason(): void {
		$this->signIn(groupIds: ['quality']);
		$this->results->method('survey')->willReturn(['id' => 's-1']);
		$this->results->method('mayRead')->willReturn(true);
		$this->results->method('exportTable')->willThrowException(new SurveyResultsWithheldException(count: 2, reason: 'Only 2 of the 5.'));

		$response = $this->controller->export('surveys', 's-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Only 2 of the 5.', 'withheld' => true, 'count' => 2], $response->getData());
	}//end testAWithheldExportIsRefusedWithTheCountAndReason()

	/**
	 * The export is a CSV with the labels as header and one line per row, in column order.
	 *
	 * @return void
	 */
	public function testTheExportIsACsvInColumnOrder(): void {
		$this->signIn(groupIds: ['quality']);
		$this->results->method('survey')->willReturn(['id' => 's-1']);
		$this->results->method('mayRead')->willReturn(true);
		$this->results->method('exportTable')->willReturn(
			new SurveyAnswerTable(
				title: 'After closing',
				columns: ['surveyVersion', 'respondent', 'Why, then?'],
				rows: [
					['Why, then?' => 'Slow "reply"', 'surveyVersion' => 2, 'respondent' => 'a@example.org'],
					['surveyVersion' => 2, 'respondent' => null, 'Why, then?' => ['x' => 1]],
				]
			)
		);

		$response = $this->controller->export('surveys', 's-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(
			"surveyVersion,respondent,\"Why, then?\"\n2,a@example.org,\"Slow \"\"reply\"\"\"\n2,,\"{\"\"x\"\":1}\"\n",
			$response->render()
		);
		$this->assertSame('text/csv', $response->getHeaders()['Content-Type']);
	}//end testTheExportIsACsvInColumnOrder()

	/**
	 * An answer or a label that would start a spreadsheet formula is written with a leading
	 * apostrophe, as every other CSV export does; numbers and plain text are unchanged.
	 *
	 * @return void
	 */
	public function testTheExportNeutralisesFormulaCells(): void {
		$this->signIn(groupIds: ['quality']);
		$this->results->method('survey')->willReturn(['id' => 's-1']);
		$this->results->method('mayRead')->willReturn(true);
		$this->results->method('exportTable')->willReturn(
			new SurveyAnswerTable(
				title: 'After closing',
				columns: ['score', '=Label', 'note'],
				rows: [
					['score' => -3, '=Label' => '=HYPERLINK("x")', 'note' => '+1 call'],
					['score' => '-2', '=Label' => '@SUM(A1)', 'note' => 'fine'],
				]
			)
		);

		$response = $this->controller->export('surveys', 's-1');

		$this->assertSame(
			"score,'=Label,note\n-3,\"'=HYPERLINK(\"\"x\"\")\",\"'+1 call\"\n-2,'@SUM(A1),fine\n",
			$response->render()
		);
	}//end testTheExportNeutralisesFormulaCells()
}//end class
