<?php

/**
 * Characterisation: every outcome of ContentReportController::update().
 *
 * Pins the allowed AND refused outcomes of recording a review, with their
 * status and body: no session, not a reviewer, an unknown report (by uuid and
 * by numeric id), a missing or unknown status, a failed write, and a recorded
 * outcome. A refused call must never reach the write.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ContentReportControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of ContentReportController::update().
 */
final class ContentReportControllerUpdateGoldenTest extends TestCase {

	/**
	 * Run update() as the given caller.
	 *
	 * @param string|null $uid    The signed-in user, or null.
	 * @param array       $groups The caller's groups.
	 * @param array       $params The request parameters.
	 * @param string      $id     The report identifier.
	 * @param string      $write  How the write goes: 'ok', 'fails' or 'never'.
	 * @param bool        $found  Whether the report resolves.
	 *
	 * @return array{0: int, 1: array} The status and body.
	 */
	private function update(?string $uid, array $groups, array $params, string $id = 'report-uuid', string $write = 'ok', bool $found = true): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ($params[$key] ?? null));

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->method('getValueInt')->willReturn(ContentReportService::DEFAULT_RETENTION_DAYS);

		$report = new ContentReport();
		$report->setUuid('report-uuid');
		$report->setObjectUuid('object-uuid');
		$report->setReviewerGroup('content-reviewers');

		$reports = $this->createMock(ContentReportMapper::class);
		$reports->method('findByUuid')->willReturn($found === true ? $report : null);
		if ($found === true) {
			$reports->method('find')->willReturn($report);
		} else {
			$reports->method('find')->willThrowException(new DoesNotExistException('gone'));
		}

		if ($write === 'never') {
			$reports->expects($this->never())->method('update');
		} elseif ($write === 'fails') {
			$reports->method('update')->willThrowException(new RuntimeException('disk full'));
		} else {
			$reports->method('update')->willReturnArgument(0);
		}

		$service    = new ContentReportService($reports, $config, $groupManager, $this->createMock(LoggerInterface::class));
		$controller = ContentReportControllerFactory::create('openregister', $request, $reports, $service, $this->createMock(MagicMapper::class), $session);
		$response   = $controller->update($id);
		$data       = $response->getData();
		unset($data['created'], $data['updated']);

		return [$response->getStatus(), $data];
	}//end update()

	/**
	 * The refused outcomes, none of which reaches the write.
	 *
	 * @return void
	 */
	public function testRefusalsMatchTheGolden(): void {
		$this->assertSame([401, ['error' => 'Authentication required']], $this->update(null, [], ['status' => 'upheld'], write: 'never'));
		$this->assertSame(
			[403, ['error' => 'Reading reported content requires the reviewer group', 'reviewerGroup' => 'content-reviewers']],
			$this->update('collega', ['users'], ['status' => 'upheld'], write: 'never')
		);
		$this->assertSame([404, ['error' => 'Not Found', 'identifier' => 'nope']], $this->update('reviewer', ['content-reviewers'], ['status' => 'upheld'], 'nope', 'never', false));
		$this->assertSame([404, ['error' => 'Not Found', 'identifier' => '42']], $this->update('reviewer', ['content-reviewers'], ['status' => 'upheld'], '42', 'never', false));

		$invalid = [422, ['error' => 'status must be one of: '.implode(', ', ContentReport::STATUS_VOCABULARY)]];
		$this->assertSame($invalid, $this->update('reviewer', ['content-reviewers'], [], write: 'never'));
		$this->assertSame($invalid, $this->update('reviewer', ['content-reviewers'], ['status' => '  '], write: 'never'));
		$this->assertSame($invalid, $this->update('reviewer', ['content-reviewers'], ['status' => 'deleted'], write: 'never'));
	}//end testRefusalsMatchTheGolden()

	/**
	 * A failed write is refused with its message.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsRefusedWithItsMessage(): void {
		$this->assertSame([422, ['error' => 'disk full']], $this->update('reviewer', ['content-reviewers'], ['status' => 'upheld'], write: 'fails'));
	}//end testAFailedWriteIsRefusedWithItsMessage()

	/**
	 * A reviewer records an outcome from the vocabulary, by uuid or numeric id, trimmed.
	 *
	 * @return void
	 */
	public function testAReviewerRecordsTheOutcome(): void {
		[$status, $data] = $this->update('reviewer', ['content-reviewers'], ['status' => ' upheld ']);
		$this->assertSame(200, $status);
		$this->assertSame('upheld', $data['status']);
		$this->assertSame('report-uuid', $data['uuid']);

		[$status, $data] = $this->update('reviewer', ['content-reviewers'], ['status' => 'dismissed'], '42');
		$this->assertSame(200, $status);
		$this->assertSame('dismissed', $data['status']);
	}//end testAReviewerRecordsTheOutcome()
}//end class
