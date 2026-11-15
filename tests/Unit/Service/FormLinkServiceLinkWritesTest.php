<?php

/**
 * Unit tests for FormLinkService link writes, metadata snapshots and picker rows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Db\FormSubmissionRef;
use OCA\OpenRegister\Service\FormLinkScope;
use OCA\OpenRegister\Service\FormLinkService;
use OCA\OpenRegister\Service\FormsAppRows;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\NewForm;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\FormLinkService
 * @covers \OCA\OpenRegister\Service\FormLinkScope
 * @uses   \OCA\OpenRegister\Db\FormLink
 * @uses   \OCA\OpenRegister\Db\FormSubmissionRef
 * @uses   \OCA\OpenRegister\Service\FormsAppRows
 * @uses   \OCA\OpenRegister\Service\LinkServiceRuntime
 * @uses   \OCA\OpenRegister\Service\NewForm
 */
final class FormLinkServiceLinkWritesTest extends TestCase {

	/**
	 * @var FormLinkMapper&MockObject
	 */
	private FormLinkMapper $mapper;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Named parameters the query builder was given, in order.
	 *
	 * @var list<mixed>
	 */
	private array $params = [];

	/**
	 * Tables the query builder selected from, in order.
	 *
	 * @var list<string>
	 */
	private array $tables = [];

	/**
	 * The service under test.
	 *
	 * @var FormLinkService
	 */
	private FormLinkService $service;

	/**
	 * Build the service with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(FormLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findFormLink', 'findSubmissionLink', 'insert'])
			->getMock();
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->appManager  = $this->createMock(IAppManager::class);
		$this->db          = $this->createMock(IDBConnection::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger      = $this->createMock(LoggerInterface::class);

		$this->service = new FormLinkService(
			$this->mapper,
			$this->createMock(ContainerInterface::class),
			$this->db,
			new LinkServiceRuntime($this->appManager, $this->userSession, $this->logger)
		);
	}//end setUp()

	/**
	 * Log a user in.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function login(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end login()

	/**
	 * Make the db hand out these results, one per executeQuery() call.
	 *
	 * @param list<IResult|RuntimeException> $results The results in order.
	 *
	 * @return void
	 */
	private function queries(array $results): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static fn (string $col, mixed $param): string => $col . '=' . (string)$param
		);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'where', 'orderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('from')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->tables[] = $table;
				return $qb;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->params[] = $value;
				return ':p' . count($this->params);
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			static function () use (&$results): IResult {
				$next = array_shift($results);
				if ($next instanceof RuntimeException) {
					throw $next;
				}

				return $next;
			}
		);
		$this->db->method('getQueryBuilder')->willReturn($qb);
	}//end queries()

	/**
	 * A result yielding these rows from fetch() and this value from fetchOne().
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 * @param mixed                      $one  The fetchOne() value.
	 *
	 * @return IResult
	 */
	private function rows(array $rows, mixed $one = false): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$result->method('fetchOne')->willReturn($one);
		return $result;
	}//end rows()

	/**
	 * A stored link row.
	 *
	 * @param integer      $formId       The form id.
	 * @param integer|null $submissionId The submission id, or null for a form-level link.
	 *
	 * @return FormLink
	 */
	private function storedLink(int $formId, ?int $submissionId = null): FormLink {
		$link = new FormLink();
		$link->setFormId($formId);
		$link->setSubmissionId($submissionId);
		return $link;
	}//end storedLink()

	/**
	 * A new form link snapshots the NC Forms row it points at.
	 *
	 * @return void
	 */
	public function testLinkFormToSnapshotsTheFormRow(): void {
		$this->login('alice');
		$this->appManager->method('isInstalled')->with('forms')->willReturn(true);
		$expires = time() + 86400;
		$this->queries([$this->rows([['id' => 5, 'hash' => 'h5', 'title' => 'Intake', 'expires' => $expires, 'state' => 0]])]);
		$this->mapper->expects($this->once())->method('findFormLink')->with('obj-1', 5)->willReturn(null);

		$scope = (new FormLinkScope('obj-1', 3))->inSchema(9);
		$link  = $this->service->linkFormTo($scope, 5);

		$this->assertSame('obj-1', $link->getObjectUuid());
		$this->assertSame(3, $link->getRegisterId());
		$this->assertSame(9, $link->getSchemaId());
		$this->assertSame('alice', $link->getLinkedBy());
		$this->assertSame(5, $link->getFormId());
		$this->assertNull($link->getSubmissionId());
		$this->assertSame('h5', $link->getFormHash());
		$this->assertSame('Intake', $link->getTitle());
		$this->assertSame('open', $link->getStatus());
		$this->assertSame($expires, $link->getExpiresAt()->getTimestamp());
		$this->assertInstanceOf(DateTime::class, $link->getLinkedAt());
		$this->assertSame(['forms_v2_forms'], $this->tables);
		$this->assertSame([5], $this->params);
	}//end testLinkFormToSnapshotsTheFormRow()

	/**
	 * A form that never expires gets no expiry and keeps its state's status.
	 *
	 * @return void
	 */
	public function testLinkFormToWithoutExpiryKeepsExpiresAtNull(): void {
		$this->login();
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->queries([$this->rows([['id' => 5, 'state' => 2]])]);
		$this->mapper->method('findFormLink')->willReturn(null);

		$link = $this->service->linkFormTo(new FormLinkScope('obj-1', 3), 5);

		$this->assertNull($link->getSchemaId());
		$this->assertSame('', $link->getFormHash());
		$this->assertSame('', $link->getTitle());
		$this->assertSame('draft', $link->getStatus());
		$this->assertNull($link->getExpiresAt());
	}//end testLinkFormToWithoutExpiryKeepsExpiresAtNull()

	/**
	 * A form id that NC Forms does not know is linked without metadata.
	 *
	 * @return void
	 */
	public function testLinkFormToUnknownFormInsertsBareRow(): void {
		$this->login();
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->queries([$this->rows([])]);
		$this->mapper->method('findFormLink')->willReturn(null);

		$link = $this->service->linkFormTo(new FormLinkScope('obj-1', 3), 42);

		$this->assertSame(42, $link->getFormId());
		$this->assertNull($link->getFormHash());
		$this->assertNull($link->getTitle());
		$this->assertNull($link->getStatus());
		$this->assertNull($link->getExpiresAt());
	}//end testLinkFormToUnknownFormInsertsBareRow()

	/**
	 * A failing metadata query is logged at debug level and the row is still inserted.
	 *
	 * @return void
	 */
	public function testLinkFormToSurvivesAFailingSnapshot(): void {
		$this->login();
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->queries([new RuntimeException('table missing')]);
		$this->mapper->method('findFormLink')->willReturn(null);
		$this->logger->expects($this->once())->method('debug')
			->with('Form metadata snapshot failed: table missing');

		$link = $this->service->linkFormTo(new FormLinkScope('obj-1', 3), 7);

		$this->assertSame(7, $link->getFormId());
		$this->assertNull($link->getTitle());
	}//end testLinkFormToSurvivesAFailingSnapshot()

	/**
	 * Without NC Forms the row is inserted bare and the db is never queried.
	 *
	 * @return void
	 */
	public function testLinkFormToWithoutFormsAppSkipsTheSnapshot(): void {
		$this->login();
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->mapper->method('findFormLink')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert');

		$link = $this->service->linkFormTo(new FormLinkScope('obj-1', 3), 7);

		$this->assertSame(7, $link->getFormId());
		$this->assertNull($link->getStatus());
	}//end testLinkFormToWithoutFormsAppSkipsTheSnapshot()

	/**
	 * A new submission link carries the submission id and its form's metadata.
	 *
	 * @return void
	 */
	public function testLinkSubmissionToInsertsASubmissionRow(): void {
		$this->login('bob');
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->queries([$this->rows([['id' => 5, 'hash' => 'h5', 'title' => 'Intake', 'expires' => 1, 'state' => 0]])]);
		$ref = new FormSubmissionRef(5, 11);
		$this->mapper->expects($this->once())->method('findSubmissionLink')
			->with('obj-1', $ref)->willReturn(null);

		$link = $this->service->linkSubmissionTo(new FormLinkScope('obj-1', 3), $ref);

		$this->assertSame(5, $link->getFormId());
		$this->assertSame(11, $link->getSubmissionId());
		$this->assertSame('bob', $link->getLinkedBy());
		$this->assertSame('closed', $link->getStatus());
	}//end testLinkSubmissionToInsertsASubmissionRow()

	/**
	 * Linking a submission needs a logged-in user.
	 *
	 * @return void
	 */
	public function testLinkSubmissionToRequiresAUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->mapper->expects($this->never())->method('findSubmissionLink');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);
		$this->expectExceptionMessage('No user logged in');
		$this->service->linkSubmissionTo(new FormLinkScope('obj-1', 3), new FormSubmissionRef(5, 11));
	}//end testLinkSubmissionToRequiresAUser()

	/**
	 * Creating a form while the Forms classes are not loadable is refused with 503.
	 *
	 * @return void
	 */
	public function testCreateAndLinkFormRefusesWhenFormsClassesAreMissing(): void {
		if (class_exists('OCA\\Forms\\Db\\Form') === true) {
			$this->markTestSkipped('NC Forms classes are declared in this process.');
		}

		$this->login();
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('NC Forms classes not available');
		$this->service->createAndLinkForm(new FormLinkScope('obj-1', 3), new NewForm('T'));
	}//end testCreateAndLinkFormRefusesWhenFormsClassesAreMissing()

	/**
	 * The picker lists the user's forms with status, expiry, submission count and linked flag.
	 *
	 * @return void
	 */
	public function testGetAvailableFormsMapsOwnedForms(): void {
		$this->login('alice');
		$this->appManager->method('isInstalled')->willReturn(true);
		$future = time() + 3600;
		$this->queries(
			[
				$this->rows(
					[
						['id' => '5', 'hash' => 'h5', 'title' => 'Intake', 'description' => 'D', 'expires' => (string)$future, 'state' => '0'],
						['id' => 6, 'hash' => null, 'title' => null, 'expires' => 0, 'state' => 1],
					]
				),
				$this->rows([], '4'),
				new RuntimeException('no submissions table'),
			]
		);
		$this->mapper->expects($this->once())->method('findByObjectUuid')->with('obj-1')
			->willReturn([$this->storedLink(5), $this->storedLink(6, 99)]);

		$rows = $this->service->getAvailableForms('obj-1');

		$this->assertSame(
			[
				[
					'id' => 5,
					'hash' => 'h5',
					'title' => 'Intake',
					'description' => 'D',
					'status' => 'open',
					'expiresAt' => gmdate(DATE_ATOM, $future),
					'submissionCount' => 4,
					'linked' => true,
				],
				[
					'id' => 6,
					'hash' => '',
					'title' => '',
					'description' => '',
					'status' => 'archived',
					'expiresAt' => null,
					'submissionCount' => 0,
					'linked' => false,
				],
			],
			$rows
		);
		$this->assertSame('alice', $this->params[0]);
		$this->assertSame(['forms_v2_forms', 'forms_v2_submissions', 'forms_v2_submissions'], $this->tables);
	}//end testGetAvailableFormsMapsOwnedForms()

	/**
	 * Without an object nothing is marked linked and the link table is not read.
	 *
	 * @return void
	 */
	public function testGetAvailableFormsWithoutObjectMarksNothingLinked(): void {
		$this->login();
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->queries([$this->rows([['id' => 5]]), $this->rows([], 0)]);
		$this->mapper->expects($this->never())->method('findByObjectUuid');

		$rows = $this->service->getAvailableForms();

		$this->assertCount(1, $rows);
		$this->assertFalse($rows[0]['linked']);
		$this->assertSame('open', $rows[0]['status']);
	}//end testGetAvailableFormsWithoutObjectMarksNothingLinked()

	/**
	 * A failing forms query yields an empty picker and a debug log line.
	 *
	 * @return void
	 */
	public function testGetAvailableFormsReturnsEmptyWhenTheQueryFails(): void {
		$this->login();
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->queries([new RuntimeException('boom')]);
		$this->logger->expects($this->once())->method('debug')->with('Available-forms query failed: boom');
		$this->mapper->expects($this->never())->method('findByObjectUuid');

		$this->assertSame([], $this->service->getAvailableForms('obj-1'));
	}//end testGetAvailableFormsReturnsEmptyWhenTheQueryFails()
}//end class
