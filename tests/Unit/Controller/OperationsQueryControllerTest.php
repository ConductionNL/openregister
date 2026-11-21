<?php

/**
 * The admin query console endpoints: a run answers the result, a writing query
 * is 400 READ_ONLY, an export with no list is 422, and an exported cell that
 * would read as a formula is written inert.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OperationsQueryController;
use OCA\OpenRegister\Exception\ReadOnlyQueryException;
use OCA\OpenRegister\Service\GraphQL\AdminQueryAudit;
use OCA\OpenRegister\Service\GraphQL\AdminQueryExport;
use OCA\OpenRegister\Service\GraphQL\AdminQueryRunner;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\OperationsQueryController
 * @uses \OCA\OpenRegister\Service\GraphQL\AdminQueryExport
 * @uses \OCA\OpenRegister\Service\Export\SpreadsheetCell
 * @uses \OCA\OpenRegister\Exception\NoTabularResultException
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class OperationsQueryControllerTest extends TestCase {

	/** @var AdminQueryRunner&MockObject */
	private AdminQueryRunner $runner;

	/** @var AdminQueryAudit&MockObject */
	private AdminQueryAudit $audit;

	/** @var IRequest&MockObject */
	private IRequest $request;

	protected function setUp(): void {
		$this->runner = $this->createMock(AdminQueryRunner::class);
		$this->audit = $this->createMock(AdminQueryAudit::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * A controller wired to a request answering the given params.
	 *
	 * @param array<string, mixed> $params The params getParam() answers.
	 *
	 * @return OperationsQueryController The controller.
	 */
	private function controller(array $params): OperationsQueryController {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		return new OperationsQueryController($this->request, $this->runner, new AdminQueryExport(), $this->audit);
	}//end controller()

	public function testARunAnswersTheResultAndAuditsIt(): void {
		$this->runner->method('run')->willReturn(['data' => ['things' => []], 'extensions' => ['rows' => 0, 'truncated' => false, 'durationMs' => 5]]);
		$this->audit->expects($this->once())->method('record')->with(
			'query.run',
			'{ things { id } }',
			['s' => 'x'],
			null,
			['rows' => 0, 'truncated' => false, 'durationMs' => 5, 'outcome' => 'ok']
		);

		$response = $this->controller(['query' => '{ things { id } }', 'variables' => ['s' => 'x']])->run();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['data' => ['things' => []], 'extensions' => ['rows' => 0, 'truncated' => false, 'durationMs' => 5]], $response->getData());
	}//end testARunAnswersTheResultAndAuditsIt()

	public function testAWritingQueryIsFourHundredReadOnlyAndAuditedAsRefused(): void {
		$this->runner->method('run')->willThrowException(new ReadOnlyQueryException('A mutation is not allowed here.'));
		$this->audit->expects($this->once())->method('record')->with(
			'query.run',
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->callback(static fn (array $outcome): bool => $outcome['outcome'] === 'read-only-refused')
		);

		$response = $this->controller(['query' => 'mutation { x { id } }'])->run();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('READ_ONLY', $response->getData()['error']);
	}//end testAWritingQueryIsFourHundredReadOnlyAndAuditedAsRefused()

	public function testATimeoutInTheResultIsClassifiedAsTimeout(): void {
		$this->runner->method('run')->willReturn([
			'data' => ['things' => null],
			'errors' => [['message' => 'too slow', 'extensions' => ['code' => 'QUERY_TIMEOUT']]],
			'extensions' => ['rows' => 1000, 'truncated' => true, 'durationMs' => 30000],
		]);
		$this->audit->expects($this->once())->method('record')->with(
			'query.run',
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->callback(static fn (array $outcome): bool => $outcome['outcome'] === 'timeout' && $outcome['truncated'] === true)
		);

		$this->controller(['query' => '{ things { id } }'])->run();
	}//end testATimeoutInTheResultIsClassifiedAsTimeout()

	public function testAnExportDownloadsTheFirstListAsCsvWithFormulaSafeCells(): void {
		$this->runner->method('run')->willReturn([
			'data' => ['things' => ['edges' => [['node' => ['id' => '1', 'label' => '=SUM(A1)']], ['node' => ['id' => '2', 'label' => 'plain']]]]],
			'extensions' => ['rows' => 2, 'truncated' => false, 'durationMs' => 7],
		]);
		$this->audit->expects($this->once())->method('record')->with(
			'query.export',
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->callback(static fn (array $outcome): bool => $outcome['format'] === 'csv' && $outcome['outcome'] === 'ok')
		);

		$response = $this->controller(['query' => '{ things { edges { node { id label } } } }', 'format' => 'csv'])->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$body = $response->render();
		$this->assertStringStartsWith("\xEF\xBB\xBF", $body);
		$this->assertStringContainsString("'=SUM(A1)", $body);
		$this->assertStringContainsString('id,label', $body);
	}//end testAnExportDownloadsTheFirstListAsCsvWithFormulaSafeCells()

	public function testAnExportCanBeJson(): void {
		$this->runner->method('run')->willReturn([
			'data' => ['things' => ['edges' => [['node' => ['id' => '1']]]]],
			'extensions' => ['rows' => 1, 'truncated' => false, 'durationMs' => 3],
		]);

		$response = $this->controller(['query' => '{ things { edges { node { id } } } }', 'format' => 'json'])->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame([['id' => '1']], json_decode($response->render(), true));
	}//end testAnExportCanBeJson()

	public function testAnExportWithNoListIsFourTwentyTwo(): void {
		$this->runner->method('run')->willReturn(
			['data' => ['thing' => ['id' => '1']], 'extensions' => ['rows' => 0, 'truncated' => false, 'durationMs' => 1]]
		);
		$this->audit->expects($this->once())->method('record')->with(
			'query.export',
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->callback(static fn (array $outcome): bool => $outcome['outcome'] === 'no-list')
		);

		$response = $this->controller(['query' => '{ thing { id } }'])->export();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('NO_LIST', $response->getData()['error']);
	}//end testAnExportWithNoListIsFourTwentyTwo()

	public function testAWritingExportIsFourHundredReadOnly(): void {
		$this->runner->method('run')->willThrowException(new ReadOnlyQueryException('A mutation is not allowed here.'));

		$response = $this->controller(['query' => 'mutation { x { id } }'])->export();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('READ_ONLY', $response->getData()['error']);
	}//end testAWritingExportIsFourHundredReadOnly()
}//end class
