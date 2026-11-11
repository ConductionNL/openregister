<?php

/**
 * The listing query the content report index hands the mapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ContentReportControllerFactory;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContentReportControllerListQueryTest extends TestCase {
	/**
	 * The query the mapper received, or null before index() ran.
	 *
	 * @var ListQuery|null
	 */
	private ?ListQuery $query = null;

	private function index(array $params): int {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ($params[$key] ?? null));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reviewer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['content-reviewers']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$reports = $this->createMock(ContentReportMapper::class);
		$reports->method('findAll')->willReturnCallback(function (ListQuery $query): array {
			$this->query = $query;
			return [];
		});

		$service = new ContentReportService($reports, $config, $groupManager, $this->createMock(LoggerInterface::class));
		$objects = $this->createMock(MagicMapper::class);

		return ContentReportControllerFactory::create('openregister', $request, $reports, $service, $objects, $session)
			->index()->getStatus();
	}//end index()

	public function testTheStatusAndOrganisationParametersBecomeUnboundedFilters(): void {
		self::assertSame(Http::STATUS_OK, $this->index(['status' => 'open', 'organisation' => 'org-1']));

		self::assertTrue($this->query->isUnbounded());
		self::assertSame(['status' => 'open', 'organisation_id' => 'org-1'], $this->query->filters);
	}//end testTheStatusAndOrganisationParametersBecomeUnboundedFilters()

	public function testMissingParametersAreNullFilters(): void {
		self::assertSame(Http::STATUS_OK, $this->index([]));

		self::assertSame(['status' => null, 'organisation_id' => null], $this->query->filters);
	}//end testMissingParametersAreNullFilters()
}//end class
