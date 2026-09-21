<?php

/**
 * Unit tests for the content report API's access rules.
 *
 * Filing is open to any authenticated caller; the copy and the report list
 * are the reviewer group's. The refusal scenario is `@e2e exclude` in the
 * delta and is asserted here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ContentReportController;
use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContentReportControllerTest extends TestCase {
	/**
	 * The report every lookup in these tests resolves to.
	 *
	 * @var ContentReport
	 */
	private ContentReport $report;

	protected function setUp(): void {
		$this->report = new ContentReport();
		$this->report->setUuid('report-uuid');
		$this->report->setObjectUuid('object-uuid');
		$this->report->setReviewerGroup('content-reviewers');
		$this->report->setCopy(['object' => ['bericht' => 'bewijs']]);
		$this->report->setCopyHash(ContentReport::hashCopy(['object' => ['bericht' => 'bewijs']]));
	}//end setUp()

	private function controller(?string $uid, array $groups, array $params = []): ContentReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ($params[$key] ?? null)
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->method('getValueInt')->willReturn(ContentReportService::DEFAULT_RETENTION_DAYS);

		$reports = $this->createMock(ContentReportMapper::class);
		$reports->method('findByUuid')->willReturn($this->report);
		$reports->method('findAll')->willReturn([$this->report]);
		$reports->method('insert')->willReturnArgument(0);

		$objects = $this->createMock(MagicMapper::class);
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setObject(['bericht' => 'nieuw']);
		$objects->method('find')->willReturn($object);

		$service = new ContentReportService($reports, $config, $groupManager, $this->createMock(LoggerInterface::class));

		return new ContentReportController('openregister', $request, $reports, $service, $objects, $session);
	}//end controller()

	public function testAReviewerReadsTheCopy(): void {
		$response = $this->controller('reviewer', ['content-reviewers'])->copy('report-uuid');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('bewijs', $response->getData()['copy']['object']['bericht']);
		self::assertTrue($response->getData()['copyIntact']);
	}//end testAReviewerReadsTheCopy()

	public function testSomebodyWhoIsNotAReviewerIsRefusedTheCopy(): void {
		$response = $this->controller('collega', ['users'])->copy('report-uuid');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayNotHasKey('copy', $response->getData());
		self::assertStringNotContainsString('bewijs', (string)json_encode($response->getData()));
	}//end testSomebodyWhoIsNotAReviewerIsRefusedTheCopy()

	public function testAnAdministratorIsNotAReviewerByDefault(): void {
		$response = $this->controller('beheerder', ['admin'])->copy('report-uuid');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnAdministratorIsNotAReviewerByDefault()

	public function testTheListIsRefusedToSomebodyWhoIsNotAReviewer(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller('collega', ['users'])->index()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller('collega', ['users'])->show('report-uuid')->getStatus());
	}//end testTheListIsRefusedToSomebodyWhoIsNotAReviewer()

	public function testAnAnonymousCallerIsUnauthorised(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null, [])->copy('report-uuid')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null, [])->create()->getStatus());
	}//end testAnAnonymousCallerIsUnauthorised()

	public function testAnyAuthenticatedCallerCanFileAReport(): void {
		$response = $this->controller('collega', ['users'], ['object' => 'object-uuid', 'reason' => 'beledigend'])->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		// The filer gets the report back, never the copy.
		self::assertArrayNotHasKey('copy', $response->getData());
	}//end testAnyAuthenticatedCallerCanFileAReport()

	public function testAReportWithoutAReasonIsRefused(): void {
		$response = $this->controller('collega', ['users'], ['object' => 'object-uuid'])->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testAReportWithoutAReasonIsRefused()

	public function testAReviewerRecordsAnOutcomeFromTheVocabularyOnly(): void {
		$ok = $this->controller('reviewer', ['content-reviewers'], ['status' => 'upheld'])->update('report-uuid');
		self::assertSame(Http::STATUS_OK, $ok->getStatus());

		$bad = $this->controller('reviewer', ['content-reviewers'], ['status' => 'deleted'])->update('report-uuid');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $bad->getStatus());
	}//end testAReviewerRecordsAnOutcomeFromTheVocabularyOnly()
}//end class
