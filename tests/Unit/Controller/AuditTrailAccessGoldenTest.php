<?php

/**
 * Golden outcomes of the audit-trail reads and export.
 *
 * Pins, on the controller as it was before these actions and their parameter
 * parsing were split into small units: that export and the subject audit trail
 * are refused to anybody but an administrator before anything is read, the
 * paging precedence (`limit` over `_limit`, an explicit offset over the one a
 * page implies), every sort form, the masking of each schema's rows of a
 * subject trail, and the error prefixes of the failure answers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of AuditTrailController's admin reads and export.
 */
class AuditTrailAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private LogService&MockObject $logService;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);
		$this->logService = $this->createMock(LogService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}

	/**
	 * The controller.
	 *
	 * @return AuditTrailController
	 */
	private function controller(): AuditTrailController {
		return AuditTrailControllerFactory::create(
			'openregister',
			$this->request,
			$this->logService,
			$this->auditTrailMapper,
			$this->createMock(AuditHashService::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(ReadableAuditTrailLister::class),
			new AuditTrailPageService(
				$this->createMock(AuditTrailPageQuery::class),
				$this->logService,
				$this->createMock(IJobList::class)
			),
			$this->createMock(ObjectHistoryForReaders::class)
		);
	}

	/**
	 * Sign somebody in.
	 *
	 * @param bool $isAdmin Whether they administer the instance.
	 *
	 * @return void
	 */
	private function signIn(bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('u')->willReturn($isAdmin);
	}

	/**
	 * The config objects() hands the log service, for these parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed>
	 */
	private function objectsConfig(array $params): array {
		$this->signIn(true);
		$this->params = $params;
		$seen = [];
		$this->logService->method('getLogs')->willReturnCallback(
			function (...$args) use (&$seen): array {
				$seen = $args[3];
				return [];
			}
		);
		$this->logService->method('count')->willReturn(45);
		$this->logService->method('maskExplicitReveal')->willReturnArgument(0);

		$response = $this->controller()->objects('r', 's', 'o');
		$this->assertSame(200, $response->getStatus());

		return $seen + ['pages' => $response->getData()['pages']];
	}

	// --- refusals ---------------------------------------------------------

	public function testANonAdministratorMayNotExportNorReadASubjectTrail(): void {
		$this->signIn(false);
		$this->params = ['identifier' => 'bsn-1'];
		$this->logService->expects($this->never())->method('exportLogs');
		$this->auditTrailMapper->expects($this->never())->method('findByIdentifier');

		$this->assertSame(403, $this->controller()->export()->getStatus());
		$this->assertSame(403, $this->controller()->subjectAuditTrail()->getStatus());
	}

	public function testAnAnonymousCallerMayNotExportNorReadASubjectTrail(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->logService->expects($this->never())->method('exportLogs');
		$this->auditTrailMapper->expects($this->never())->method('findByIdentifier');

		$this->assertSame(401, $this->controller()->export()->getStatus());
		$this->assertSame(401, $this->controller()->subjectAuditTrail()->getStatus());
	}

	// --- paging and sort --------------------------------------------------

	public function testLimitWinsOverUnderscoreLimitAndAPageImpliesTheOffset(): void {
		$config = $this->objectsConfig(['limit' => '10', '_limit' => '99', 'page' => '3']);

		$this->assertSame(10, $config['limit']);
		$this->assertSame(3, $config['page']);
		$this->assertSame(20, $config['offset']);
		$this->assertSame(5.0, $config['pages']);
		$this->assertSame(['created' => 'DESC'], $config['sort']);
		$this->assertNull($config['search']);
	}

	public function testAnExplicitOffsetIsNotOverriddenByThePage(): void {
		$config = $this->objectsConfig(['_offset' => '7', '_page' => '4', '_search' => 'x']);

		$this->assertSame(20, $config['limit']);
		$this->assertSame(7, $config['offset']);
		$this->assertSame(4, $config['page']);
		$this->assertSame('x', $config['search']);
	}

	public function testNoPagingLeavesOffsetAndPageNull(): void {
		$config = $this->objectsConfig(['search' => 'a', '_search' => 'b']);

		$this->assertNull($config['offset']);
		$this->assertNull($config['page']);
		$this->assertSame('a', $config['search']);
	}

	public function testASortMapTakesEachDirectionAndDefaultsToDescending(): void {
		$config = $this->objectsConfig(['_sort' => ['action' => 'asc', 'created' => 'up']]);

		$this->assertSame(['action' => 'ASC', 'created' => 'DESC'], $config['sort']);
	}

	public function testASingleSortFieldTakesItsOrder(): void {
		$config = $this->objectsConfig(['sort' => 'user', '_order' => 'asc']);

		$this->assertSame(['user' => 'ASC'], $config['sort']);
	}

	public function testASingleSortFieldWithoutAnOrderIsDescending(): void {
		$config = $this->objectsConfig(['_sort' => 'user']);

		$this->assertSame(['user' => 'DESC'], $config['sort']);
	}

	public function testAnEmptySortMapFallsBackToNewestFirst(): void {
		$config = $this->objectsConfig(['sort' => []]);

		$this->assertSame(['created' => 'DESC'], $config['sort']);
	}

	// --- subject trail ----------------------------------------------------

	public function testASubjectTrailMasksEverySchemasRowsAndLeavesTheRestAlone(): void {
		$this->signIn(true);
		$this->params = ['identifier' => 'bsn-1'];
		$this->auditTrailMapper->method('findByIdentifier')->with('bsn-1')
			->willReturn(['5' => [['id' => 1]], 'note' => 'n']);
		$this->logService->expects($this->once())->method('maskExplicitReveal')->with([['id' => 1]])->willReturn([['id' => 'masked']]);

		$response = $this->controller()->subjectAuditTrail();

		$this->assertSame(['5' => [['id' => 'masked']], 'note' => 'n'], $response->getData());
	}

	public function testAFailingSubjectTrailIs500WithItsPrefix(): void {
		$this->signIn(true);
		$this->params = ['identifier' => 'bsn-1'];
		$this->auditTrailMapper->method('findByIdentifier')->willThrowException(new \RuntimeException('db'));

		$response = $this->controller()->subjectAuditTrail();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Subject audit-trail lookup failed: db'], $response->getData());
	}

	// --- export -----------------------------------------------------------

	public function testAnExportPassesItsFlagsAndSizesTheContent(): void {
		$this->signIn(true);
		$this->params = ['format' => 'json', 'includeChanges' => 'false', 'includeMetadata' => 'true', 'search' => 'q'];
		$this->logService->expects($this->once())->method('exportLogs')->willReturnCallback(
			function (string $format, array $config): array {
				$this->assertSame('json', $format);
				$this->assertSame(['filters', 'search', 'includeChanges', 'includeMetadata'], array_keys($config));
				$this->assertSame('q', $config['search']);
				$this->assertFalse($config['includeChanges']);
				$this->assertTrue($config['includeMetadata']);
				return ['content' => 'abcd', 'filename' => 'f.json', 'contentType' => 'application/json'];
			}
		);

		$response = $this->controller()->export();

		$this->assertSame(
			['success' => true, 'data' => ['content' => 'abcd', 'filename' => 'f.json', 'contentType' => 'application/json', 'size' => 4]],
			$response->getData()
		);
	}

	public function testAnExportWhoseContentIsNoStringHasSizeZero(): void {
		$this->signIn(true);
		$this->logService->method('exportLogs')
			->willReturn(['content' => ['rows'], 'filename' => 'f', 'contentType' => 't']);

		$this->assertSame(0, $this->controller()->export()->getData()['data']['size']);
	}
}
