<?php

/**
 * Filing a content report refuses a missing object id, an unknown object and a failed write with the
 * right status; the reviewer list, a single report and the copy answer 401 or 404 where they must.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ContentReportController;
use OCA\OpenRegister\Controller\Support\ContentReportControllerServices;
use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapperInterface;
use OCA\OpenRegister\Db\LockTerms;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SoftDeletion;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Audit\ContentReportFiling;
use OCA\OpenRegister\Service\Audit\ContentReportServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A filer and a reviewer hitting the content report endpoints' edge paths.
 */
#[CoversClass(ContentReportController::class)]
#[UsesClass(ContentReportControllerServices::class)]
#[UsesClass(ContentReport::class)]
#[UsesClass(ContentReportFiling::class)]
#[UsesClass(ListQuery::class)]
#[UsesClass(LockTerms::class)]
#[UsesClass(SoftDeletion::class)]
final class ContentReportControllerPathsTest extends TestCase {
	/** @var ContentReportMapperInterface&MockObject */
	private ContentReportMapperInterface $reports;

	/** @var ContentReportServiceInterface&MockObject */
	private ContentReportServiceInterface $service;

	/** @var MagicObjectFinderInterface&MockObject */
	private MagicObjectFinderInterface $objects;

	/**
	 * Fresh collaborators per test; the reviewer group is `content-reviewers`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reports = $this->createMock(ContentReportMapperInterface::class);
		$this->service = $this->createMock(ContentReportServiceInterface::class);
		$this->service->method('reviewerGroup')->willReturn('content-reviewers');
		$this->objects = $this->createMock(MagicObjectFinderInterface::class);
	}

	/**
	 * The controller with the given caller (null: anonymous) and request params.
	 *
	 * @param string|null          $uid      The caller's user id, or null.
	 * @param array<string, mixed> $params   The request params.
	 * @param bool                 $reviewer Whether the service lets the caller read copies.
	 *
	 * @return ContentReportController
	 */
	private function controller(?string $uid, array $params=[], bool $reviewer=false): ContentReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service->method('mayReadCopy')->willReturn($reviewer);

		return new ContentReportController(
			$request,
			new ContentReportControllerServices(
				reports: $this->reports,
				service: $this->service,
				objects: $this->objects,
				userSession: $session
			)
		);
	}

	/**
	 * A report serialising to the given data.
	 *
	 * @param array<string, mixed> $data The serialised form.
	 *
	 * @return ContentReport
	 */
	private function report(array $data): ContentReport {
		$report = $this->createMock(ContentReport::class);
		$report->method('jsonSerialize')->willReturn($data);
		return $report;
	}

	/**
	 * A report without an object id is refused with 422 before any lookup.
	 *
	 * @return void
	 */
	public function testFilingWithoutAnObjectIsRefused(): void {
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller('collega', ['object' => '  ', 'reason' => 'beledigend'])->create();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'object is required'], $response->getData());
	}

	/**
	 * A report on an object that cannot be found is a 404 naming the object, and nothing is filed.
	 *
	 * @return void
	 */
	public function testFilingOnAnUnknownObjectIs404(): void {
		$this->objects->method('find')->with('ghost')->willThrowException(new DoesNotExistException('gone'));
		$this->service->expects($this->never())->method('file');

		$response = $this->controller('collega', ['object' => ' ghost ', 'reason' => 'beledigend'])->create();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not Found', 'object' => 'ghost'], $response->getData());
	}

	/**
	 * A failing write is a 422 carrying the service's message; the filer and trimmed reason are passed on.
	 *
	 * @return void
	 */
	public function testAFailedWriteIs422WithTheMessage(): void {
		$object = $this->createMock(ObjectEntity::class);
		$this->objects->method('find')->willReturn($object);
		$this->service->expects($this->once())
			->method('file')
			->with(
				$this->callback(
					static fn (ContentReportFiling $filing): bool => $filing->object === $object
						&& $filing->reason === 'beledigend'
						&& $filing->reporter === 'collega'
				)
			)
			->willThrowException(new RuntimeException('Report store unavailable'));

		$response = $this->controller('collega', ['object' => 'obj-1', 'reason' => ' beledigend '])->create();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Report store unavailable'], $response->getData());
	}

	/**
	 * A reviewer's list passes the status and organisation filters (blank ones as null) and counts the rows.
	 *
	 * @return void
	 */
	public function testAReviewerListsTheFilteredReports(): void {
		$captured = null;
		$this->reports->expects($this->once())
			->method('findAll')
			->willReturnCallback(
				function (ListQuery $query) use (&$captured): array {
					$captured = $query;
					return [$this->report(['uuid' => 'r1']), $this->report(['uuid' => 'r2'])];
				}
			);

		$response = $this->controller('reviewer', ['status' => 'open', 'organisation' => ' '], true)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['count' => 2, 'results' => [['uuid' => 'r1'], ['uuid' => 'r2']]], $response->getData());
		$this->assertInstanceOf(ListQuery::class, $captured);
		$this->assertTrue($captured->isUnbounded());
		$this->assertSame(['status' => 'open', 'organisation_id' => null], $captured->filters);
	}

	/**
	 * An anonymous caller is unauthorised for the list and a single report.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsUnauthorisedForListAndShow(): void {
		$this->reports->expects($this->never())->method('findAll');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->index()->getStatus());
		$this->assertSame(
			['error' => 'Authentication required'],
			$this->controller(null)->show('r1')->getData()
		);
	}

	/**
	 * A reviewer reads a report by numeric id, and an unknown numeric id is a 404.
	 *
	 * @return void
	 */
	public function testAReviewerShowsAReportByNumericIdOr404(): void {
		$this->reports->method('find')->willReturnCallback(
			function (int $id): ContentReport {
				if ($id === 7) {
					return $this->report(['id' => 7]);
				}

				throw new DoesNotExistException('no such report');
			}
		);
		$this->reports->expects($this->never())->method('findByUuid');

		$found = $this->controller('reviewer', [], true)->show('7');
		$missing = $this->controller('reviewer', [], true)->show('8');

		$this->assertSame(Http::STATUS_OK, $found->getStatus());
		$this->assertSame(['id' => 7], $found->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Not Found', 'identifier' => '8'], $missing->getData());
	}

	/**
	 * The copy of an unknown report is a 404 and no copy is read.
	 *
	 * @return void
	 */
	public function testTheCopyOfAnUnknownReportIs404(): void {
		$this->reports->method('findByUuid')->with('nope')->willReturn(null);
		$this->service->expects($this->never())->method('readCopy');

		$response = $this->controller('reviewer', [], true)->copy('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not Found', 'identifier' => 'nope'], $response->getData());
	}

	/**
	 * An update of an unknown report by a reviewer is a 404.
	 *
	 * @return void
	 */
	public function testUpdatingAnUnknownReportIs404(): void {
		$this->reports->method('findByUuid')->willReturn(null);

		$response = $this->controller('reviewer', ['status' => 'upheld'], true)->update('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
