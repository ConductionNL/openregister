<?php

/**
 * Golden test: FormLinkService link writes, metadata snapshot and picker rows.
 *
 * Pins the rows linkForm() / linkFormSubmission() insert, how the NC Forms
 * row is mapped onto status and expiry, and the picker rows
 * getAvailableForms() returns, so the methods can be split without
 * changing what they produce.
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
use OCA\OpenRegister\Service\FormLinkService;
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
 * @coversNothing
 */
final class FormLinkServiceGoldenTest extends TestCase {

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
	 * The service under test.
	 *
	 * @var FormLinkService
	 */
	private FormLinkService $service;

	/**
	 * Build the service with mocked collaborators.
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
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new FormLinkService(
			$this->mapper,
			$this->createMock(ContainerInterface::class),
			$this->appManager,
			$this->db,
			$this->userSession,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Query results the db hands out, one per executeQuery() call.
	 *
	 * @param list<IResult|RuntimeException> $results The results in order.
	 *
	 * @return void
	 */
	private function queries(array $results): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb   = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'orderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
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
	 * A result that yields these rows from fetch() and this value from fetchOne().
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 * @param mixed                      $one  The fetchOne() value.
	 *
	 * @return IResult
	 */
	private function rowsResult(array $rows, mixed $one = false): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$result->method('fetchOne')->willReturn($one);
		return $result;
	}//end rowsResult()

	/**
	 * The serialised link without its link-time timestamp.
	 *
	 * @param FormLink $link The link.
	 *
	 * @return array<string, mixed>
	 */
	private function row(FormLink $link): array {
		$this->assertInstanceOf(DateTime::class, $link->getLinkedAt());
		$row = $link->jsonSerialize();
		unset($row['linkedAt']);
		return $row;
	}//end row()

	/**
	 * Without NC Forms a new form link carries no snapshot metadata.
	 *
	 * @return void
	 */
	public function testLinkFormWithoutFormsInsertsBareRow(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->mapper->method('findFormLink')->willReturn(null);

		$link = $this->service->linkForm(objectUuid: 'obj-1', registerId: 3, formId: 42, schemaId: 7);

		$expected = [
			'objectUuid' => 'obj-1', 'registerId' => 3, 'schemaId' => 7, 'formId' => 42,
			'formHash' => null, 'submissionId' => null, 'title' => null, 'status' => null,
			'expiresAt' => null, 'linkedBy' => 'alice',
		];
		$this->assertSame($expected, array_intersect_key($this->row($link), $expected));
	}//end testLinkFormWithoutFormsInsertsBareRow()

	/**
	 * With NC Forms the link snapshots the form's title, hash, status and expiry.
	 *
	 * @return void
	 */
	public function testLinkFormSubmissionSnapshotsDraftForm(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->mapper->method('findSubmissionLink')->willReturn(null);
		$this->queries([$this->rowsResult([['id' => 42, 'hash' => 'h42', 'title' => 'Intake', 'expires' => 1700000000, 'state' => 2]])]);

		$link = $this->service->linkFormSubmission(objectUuid: 'obj-1', registerId: 3, formId: 42, submissionId: 9);

		$row = $this->row($link);
		$this->assertSame(
			['formId' => 42, 'formHash' => 'h42', 'submissionId' => 9, 'title' => 'Intake', 'status' => 'draft', 'linkedBy' => 'alice'],
			array_intersect_key($row, array_flip(['formId', 'formHash', 'submissionId', 'title', 'status', 'linkedBy']))
		);
		$this->assertNull($row['schemaId']);
		$this->assertSame(1700000000, $link->getExpiresAt()?->getTimestamp());
	}//end testLinkFormSubmissionSnapshotsDraftForm()

	/**
	 * An archived form maps to "archived"; a missing row or failing query to bare metadata.
	 *
	 * @return void
	 */
	public function testSnapshotStatusesAndFallbacks(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->mapper->method('findFormLink')->willReturn(null);
		$this->queries(
			[
				$this->rowsResult([['title' => null, 'hash' => null, 'expires' => 0, 'state' => 1]]),
				$this->rowsResult([]),
				new RuntimeException('boom'),
			]
		);

		$archived = $this->service->linkForm(objectUuid: 'o', registerId: 1, formId: 1);
		$missing  = $this->service->linkForm(objectUuid: 'o', registerId: 1, formId: 2);
		$failing  = $this->service->linkForm(objectUuid: 'o', registerId: 1, formId: 3);

		$this->assertSame(['', '', 'archived', null], [$archived->getTitle(), $archived->getFormHash(), $archived->getStatus(), $archived->getExpiresAt()]);
		$this->assertSame([null, null, null, null], [$missing->getTitle(), $missing->getFormHash(), $missing->getStatus(), $missing->getExpiresAt()]);
		$this->assertSame([null, null, null, null], [$failing->getTitle(), $failing->getFormHash(), $failing->getStatus(), $failing->getExpiresAt()]);
	}//end testSnapshotStatusesAndFallbacks()

	/**
	 * The picker lists owned forms with status, count and the linked flag.
	 *
	 * @return void
	 */
	public function testGetAvailableFormsMapsRows(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$formLink = new FormLink();
		$formLink->setFormId(5);
		$subLink = new FormLink();
		$subLink->setFormId(6);
		$subLink->setSubmissionId(1);
		$this->mapper->method('findByObjectUuid')->willReturn([$formLink, $subLink]);
		$this->queries(
			[
				$this->rowsResult(
					[
						['id' => '5', 'hash' => 'a', 'title' => 'A', 'description' => 'dA', 'expires' => '0', 'state' => '0'],
						['id' => '6', 'title' => 'B', 'state' => '1'],
					]
				),
				$this->rowsResult([], '12'),
				new RuntimeException('no submissions table'),
			]
		);

		$rows = $this->service->getAvailableForms(objectUuid: 'obj-1');

		$this->assertSame(
			[
				['id' => 5, 'hash' => 'a', 'title' => 'A', 'description' => 'dA', 'status' => 'open',
					'expiresAt' => null, 'submissionCount' => 12, 'linked' => true,
				],
				['id' => 6, 'hash' => '', 'title' => 'B', 'description' => '', 'status' => 'archived',
					'expiresAt' => null, 'submissionCount' => 0, 'linked' => false,
				],
			],
			$rows
		);
	}//end testGetAvailableFormsMapsRows()

	/**
	 * Without an object uuid nothing is flagged linked; a failing query gives no rows.
	 *
	 * @return void
	 */
	public function testGetAvailableFormsWithoutObjectAndOnFailure(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->mapper->expects($this->never())->method('findByObjectUuid');
		$this->queries([$this->rowsResult([['id' => 8, 'expires' => 1700000000, 'state' => 2]]), $this->rowsResult([], 0), new RuntimeException('down')]);

		$rows = $this->service->getAvailableForms();

		$this->assertSame(gmdate(DATE_ATOM, 1700000000), $rows[0]['expiresAt']);
		$this->assertSame(['draft', false, 0], [$rows[0]['status'], $rows[0]['linked'], $rows[0]['submissionCount']]);
		$this->assertSame([], $this->service->getAvailableForms());
	}//end testGetAvailableFormsWithoutObjectAndOnFailure()

	/**
	 * Creating a form needs a user, the Forms app and its classes, in that order.
	 *
	 * @return void
	 */
	public function testCreateAndLinkFormGuards(): void {
		$this->appManager->method('isInstalled')->willReturnOnConsecutiveCalls(false, true);

		$codes = [];
		foreach (['NC Forms app is not installed', 'NC Forms classes not available'] as $message) {
			try {
				$this->service->createAndLinkForm(objectUuid: 'o', registerId: 1, title: 't');
				$codes[] = 'none';
			} catch (Exception $e) {
				$codes[] = $e->getMessage() . ':' . $e->getCode();
			}
		}

		$this->assertSame(['NC Forms app is not installed:503', 'NC Forms classes not available:503'], $codes);
	}//end testCreateAndLinkFormGuards()

	/**
	 * Grouping keeps form rows, nests submissions and synthesises a parent for an orphan.
	 *
	 * @return void
	 */
	public function testGetLinkedFormsShape(): void {
		$form = new FormLink();
		$form->setObjectUuid('o');
		$form->setRegisterId(1);
		$form->setFormId(5);
		$sub = new FormLink();
		$sub->setObjectUuid('o');
		$sub->setRegisterId(1);
		$sub->setFormId(5);
		$sub->setSubmissionId(10);
		$orphan = new FormLink();
		$orphan->setObjectUuid('o');
		$orphan->setRegisterId(1);
		$orphan->setFormId(6);
		$orphan->setSubmissionId(11);
		$orphan->setTitle('T6');
		$this->mapper->method('findByObjectUuid')->willReturn([$sub, $form, $orphan]);

		$rows = $this->service->getLinkedForms(objectUuid: 'o');

		$this->assertSame([5, 6], array_column($rows, 'formId'));
		$this->assertSame([10], array_column($rows[0]['submissions'], 'submissionId'));
		$this->assertArrayNotHasKey('synthetic', $rows[0]);
		$this->assertSame(
			['id' => null, 'objectUuid' => 'o', 'registerId' => 1, 'schemaId' => null, 'formId' => 6, 'formHash' => null,
				'submissionId' => null, 'title' => 'T6', 'status' => null, 'expiresAt' => null, 'linkedBy' => null, 'linkedAt' => null,
			],
			array_diff_key($rows[1], ['submissions' => true, 'synthetic' => true])
		);
		$this->assertTrue($rows[1]['synthetic']);
		$this->assertSame([11], array_column($rows[1]['submissions'], 'submissionId'));
	}//end testGetLinkedFormsShape()
}//end class
