<?php

/**
 * AuditCategoryReadTest
 *
 * `GET /api/audit-trails` reads one category at a time (REQ-HSA-011): record
 * history unless the administrative changes are asked for, an unknown
 * category is a 400, and a non-administrator receives no administrative row,
 * whichever category is asked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\BackgroundJob\AuditExportSource;
use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrail\AuditPageRequest;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Service\Audit\AuditCategory;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditTrailController::class)]
#[UsesClass(AuditCategory::class)]
#[UsesClass(AuditTrailPageService::class)]
#[UsesClass(AuditExportSource::class)]
final class AuditCategoryReadTest extends TestCase {

	/** @var array<string, mixed> The request parameters. */
	private array $params = [];

	/** @var list<array<string, mixed>> The filters the legacy list was asked with. */
	private array $asked = [];

	/** @var list<AuditPageRequest> The keyset pages asked for. */
	private array $pages = [];

	/**
	 * Without a category the list reads record history only, on both paths.
	 *
	 * @return void
	 */
	public function testTheDefaultIsDomain(): void {
		$response = $this->controller(admin: true)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['category' => AuditCategory::DOMAIN]], $this->asked);

		$this->params = ['cursor' => ''];
		$this->controller(admin: true)->index();
		$this->assertCount(1, $this->pages);
		$this->assertSame(AuditCategory::DOMAIN, $this->pages[0]->criteria->filters['category']);
	}

	/**
	 * An administrator who asks for the administrative changes gets them; an unknown category is a 400.
	 *
	 * @return void
	 */
	public function testAnAdministratorReadsTheAdministrativeChanges(): void {
		$this->params = ['category' => 'administrative'];
		$this->assertSame(200, $this->controller(admin: true)->index()->getStatus());
		$this->assertSame([['category' => AuditCategory::ADMINISTRATIVE]], $this->asked);

		$this->params = ['category' => 'administrative', 'cursor' => ''];
		$this->controller(admin: true)->index();
		$this->assertSame(AuditCategory::ADMINISTRATIVE, $this->pages[0]->criteria->filters['category']);

		$this->asked  = [];
		$this->params = ['category' => 'everything'];
		$refused      = $this->controller(admin: true)->index();
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame([], $this->asked, 'An unknown category reads nothing');
	}

	/**
	 * Scenario "an object reader does not see administrative rows": a
	 * caseworker asking for them gets 403, and the list is never read.
	 *
	 * @return void
	 */
	public function testANonAdminNeverReceivesAdministrativeRows(): void {
		foreach ([['category' => 'administrative'], ['category' => 'administrative', 'cursor' => ''], []] as $params) {
			$this->params = $params;
			$response     = $this->controller(admin: false)->index();
			$this->assertSame(403, $response->getStatus(), json_encode($params));
		}

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->pages);
	}

	/**
	 * The controller over doubles that record what the list is asked for.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return AuditTrailController
	 */
	private function controller(bool $admin): AuditTrailController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));

		$log = $this->createMock(LogService::class);
		$log->method('maskExplicitReveal')->willReturnArgument(0);
		$log->method('getAllLogs')->willReturnCallback(
			function (array $params): array {
				$this->asked[] = $params['filters'];
				return [];
			}
		);
		$log->method('countAllLogs')->willReturn(0);

		$pageQuery = $this->createMock(AuditTrailPageQuery::class);
		$pageQuery->method('page')->willReturnCallback(
			function (AuditPageRequest $request): array {
				$this->pages[] = $request;
				return ['results' => [], 'nextCursor' => null];
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caseworker');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return AuditTrailControllerFactory::create(
			'openregister',
			$request,
			$log,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(AuditHashService::class),
			$session,
			$groups,
			$this->createMock(ReadableAuditTrailLister::class),
			new AuditTrailPageService(new AuditExportSource($pageQuery, $log), $this->createMock(IJobList::class)),
			$this->createMock(ObjectHistoryForReaders::class)
		);
	}
}
