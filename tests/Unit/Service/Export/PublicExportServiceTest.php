<?php

/**
 * An anonymous export: refused unless public, open formats only, one page at a time, on the trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RuleRefusedException;
use OCA\OpenRegister\Service\Export\ExportAuditRecorderInterface;
use OCA\OpenRegister\Service\Export\ExportPage;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRequest;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Export\PublicExportResult;
use OCA\OpenRegister\Service\Export\PublicExportService;
use OCA\OpenRegister\Service\ExportService;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicExportService::class)]
#[CoversClass(PublicExportResult::class)]
#[CoversClass(ExportPage::class)]
#[UsesClass(ExportRequest::class)]
#[UsesClass(ExportRefusedException::class)]
#[UsesClass(RuleRefusedException::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
final class PublicExportServiceTest extends TestCase {

	/**
	 * @var ExportService&MockObject
	 */
	private ExportService $exports;

	/**
	 * @var ExportRightService&MockObject
	 */
	private ExportRightService $rights;

	/**
	 * @var ExportAuditRecorderInterface&MockObject
	 */
	private ExportAuditRecorderInterface $audit;

	protected function setUp(): void {
		$this->exports = $this->createMock(ExportService::class);
		$this->rights  = $this->createMock(ExportRightService::class);
		$this->audit   = $this->createMock(ExportAuditRecorderInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return PublicExportService
	 */
	private function service(): PublicExportService {
		return new PublicExportService(exports: $this->exports, rights: $this->rights, audit: $this->audit);
	}//end service()

	/**
	 * An export of register 4, schema 7, on this page.
	 *
	 * @param int $page The page number.
	 *
	 * @return ExportRequest
	 */
	private function request(int $page = 1): ExportRequest {
		$register = new Register();
		$register->setId(4);
		$schema = new Schema();
		$schema->setId(7);

		return (new ExportRequest(
			register: $register,
			schema: $schema,
			filters: ['thema' => 'milieu'],
			currentUser: $this->createMock(IUser::class)
		))->withPage(page: new ExportPage(number: $page));
	}//end request()

	/**
	 * A schema the public may not export answers 401 and writes no file, but a refusal row as anonymous.
	 *
	 * @return void
	 */
	public function testAPrivateSchemaIsRefusedAndRecorded(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(
			new ExportRefusedException(rule: 'not-public', reason: 'Exporting this schema requires an account.', statusCode: 401)
		);
		$this->exports->expects($this->never())->method('csvFor');
		$this->audit->expects($this->once())->method('recordRefused')->with(
			'ad-hoc',
			'not-public',
			'Exporting this schema requires an account.',
			4,
			7,
			'anonymous'
		);

		$result = $this->service()->export(request: $this->request(), format: 'csv');

		$this->assertSame(401, $result->statusCode());
		$this->assertNull($result->content);
		$this->assertSame(0, $result->rowCount());
		$this->assertSame('not-public', $result->refusalBody()['rule']);
	}//end testAPrivateSchemaIsRefusedAndRecorded()

	/**
	 * The right is checked before the format: a private schema asked for as PDF is still 401.
	 *
	 * @return void
	 */
	public function testThePrivateRefusalComesBeforeTheFormatRefusal(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(
			new ExportRefusedException(rule: 'not-public', reason: 'Exporting this schema requires an account.', statusCode: 401)
		);

		$this->assertSame(401, $this->service()->export(request: $this->request(), format: 'pdf')->statusCode());
	}//end testThePrivateRefusalComesBeforeTheFormatRefusal()

	/**
	 * A rendered document is not an open format: 406, naming the four.
	 *
	 * @return void
	 */
	public function testAPdfIsNotAcceptable(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(null);
		$this->exports->expects($this->never())->method('pdfFor');

		$result = $this->service()->export(request: $this->request(), format: 'pdf');

		$this->assertSame(406, $result->statusCode());
		$this->assertSame('format-not-open', $result->refusalBody()['rule']);
		$this->assertSame(['csv', 'tsv', 'json', 'xml'], $result->refusalBody()['formats']);
		$this->assertStringContainsString('csv, tsv, json, xml', $result->refusalBody()['message']);
	}//end testAPdfIsNotAcceptable()

	/**
	 * Excel is refused the same way.
	 *
	 * @return void
	 */
	public function testExcelIsNotAcceptable(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(null);

		$this->assertSame(406, $this->service()->export(request: $this->request(), format: 'excel')->statusCode());
	}//end testExcelIsNotAcceptable()

	/**
	 * The first page of 23,500 rows: 10,000 rows, three pages, a link to page 2, recorded as anonymous.
	 *
	 * @return void
	 */
	public function testTheFirstPageOfALargeSetLinksToTheNext(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(null);
		$captured = null;
		$this->exports->expects($this->once())->method('xmlFor')->willReturnCallback(
			static function (ExportRequest $request) use (&$captured): string {
				$captured = $request;
				return '<objects/>';
			}
		);
		$this->exports->method('countExportTotal')->willReturn(23500);
		$this->audit->expects($this->once())->method('recordCompleted')->with(
			'ad-hoc',
			10000,
			'xml',
			null,
			4,
			7,
			'anonymous'
		);

		$result = $this->service()->export(request: $this->request(), format: 'xml');

		$this->assertSame(200, $result->statusCode());
		$this->assertSame('<objects/>', $result->content);
		// The rows are read as nobody, whoever the request said it was for.
		$this->assertNull($captured->currentUser);
		$this->assertSame(['thema' => 'milieu'], $captured->filters);
		$this->assertSame(
			[
				'X-Total-Count' => '23500',
				'X-Page' => '1',
				'X-Pages' => '3',
				'Link' => '</index.php/apps/openregister/api/objects/publicaties/subsidie/export?format=xml&_page=2>; rel="next"',
			],
			$result->headers(requestUri: '/index.php/apps/openregister/api/objects/publicaties/subsidie/export?format=xml')
		);
	}//end testTheFirstPageOfALargeSetLinksToTheNext()

	/**
	 * The last page holds the rest and sends no next link; `_page` in the URI is replaced, not repeated.
	 *
	 * @return void
	 */
	public function testTheLastPageHoldsTheRestAndHasNoNextLink(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(null);
		$this->exports->method('csvFor')->willReturn("a\n");
		$this->exports->method('countExportTotal')->willReturn(23500);
		$this->audit->expects($this->once())->method('recordCompleted')->with('ad-hoc', 3500, 'csv', null, 4, 7, 'anonymous');

		$result = $this->service()->export(request: $this->request(page: 3), format: 'csv');

		$this->assertSame(3500, $result->rowCount());
		$this->assertSame(['X-Total-Count' => '23500', 'X-Page' => '3', 'X-Pages' => '3'], $result->headers(requestUri: '/x/export?format=csv&_page=3'));
	}//end testTheLastPageHoldsTheRestAndHasNoNextLink()

	/**
	 * The middle page replaces `_page` and keeps every other parameter.
	 *
	 * @return void
	 */
	public function testTheNextLinkKeepsTheOtherParameters(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(null);
		$this->exports->method('tsvFor')->willReturn("a\n");
		$this->exports->method('countExportTotal')->willReturn(23500);

		$headers = $this->service()->export(request: $this->request(page: 2), format: 'tsv')
			->headers(requestUri: '/x/export?format=tsv&thema=milieu&_page=2');

		$this->assertSame('</x/export?format=tsv&thema=milieu&_page=3>; rel="next"', $headers['Link']);
	}//end testTheNextLinkKeepsTheOtherParameters()

	/**
	 * An empty set is one page with no rows and no link.
	 *
	 * @return void
	 */
	public function testAnEmptySetIsOnePage(): void {
		$this->rights->method('refusalForAnonymous')->willReturn(null);
		$this->exports->method('jsonFor')->willReturn('[]');
		$this->exports->method('countExportTotal')->willReturn(0);

		$result = $this->service()->export(request: $this->request(), format: 'json');

		$this->assertSame(0, $result->rowCount());
		$this->assertSame(['X-Total-Count' => '0', 'X-Page' => '1', 'X-Pages' => '1'], $result->headers(requestUri: '/x'));
	}//end testAnEmptySetIsOnePage()

	/**
	 * A register-wide export has no schema grant to read and is refused without asking.
	 *
	 * @return void
	 */
	public function testARegisterWideExportIsRefused(): void {
		$this->rights->expects($this->never())->method('refusalForAnonymous');

		$result = $this->service()->export(request: new ExportRequest(register: new Register()), format: 'csv');

		$this->assertSame(401, $result->statusCode());
		$this->assertSame('not-public', $result->refusalBody()['rule']);
	}//end testARegisterWideExportIsRefused()

	/**
	 * `_page` reads as a positive whole number, anything else as page 1.
	 *
	 * @return void
	 */
	public function testThePageParameterIsReadStrictly(): void {
		$this->assertSame(2, ExportPage::publicFrom(requested: '2')->number);
		$this->assertSame(5, ExportPage::publicFrom(requested: 5)->number);
		$this->assertSame(1, ExportPage::publicFrom(requested: '0')->number);
		$this->assertSame(1, ExportPage::publicFrom(requested: '-3')->number);
		$this->assertSame(1, ExportPage::publicFrom(requested: '2; DROP')->number);
		$this->assertSame(1, ExportPage::publicFrom(requested: null)->number);
		$this->assertSame(10000, ExportPage::publicFrom(requested: '2')->size);
	}//end testThePageParameterIsReadStrictly()
}//end class
