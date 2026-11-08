<?php

/**
 * Golden outcomes of the data subject export endpoints.
 *
 * Pins status, body and the arguments handed to the export service for the
 * request, the state read and the download, including the refusals.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\SubjectExportController;
use OCA\OpenRegister\Db\SubjectExport;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of the subject export API.
 */
final class SubjectExportControllerA24GoldenTest extends TestCase {

	/**
	 * The export service of the controller under test.
	 *
	 * @var SubjectExportService&MockObject
	 */
	private $exports;

	protected function setUp(): void {
		$this->exports = $this->createMock(SubjectExportService::class);
	}//end setUp()

	private function controller(array $params = []): SubjectExportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new SubjectExportController('openregister', $request, $this->exports);
	}//end controller()

	private function export(): SubjectExport {
		$export = new SubjectExport();
		$export->setUuid('export-1');
		$export->setSubject('123456782');

		return $export;
	}//end export()

	public function testARequestWithoutASubjectIsRefusedWith400(): void {
		$this->exports->expects($this->never())->method('request');

		foreach ([[], ['subject' => '   '], ['subject' => null]] as $params) {
			$response = $this->controller($params)->create();
			$this->assertSame([400, ['error' => 'subject is required']], [$response->getStatus(), $response->getData()]);
		}
	}//end testARequestWithoutASubjectIsRefusedWith400()

	public function testARequestPassesTheTrimmedFields(): void {
		$export = $this->export();
		$this->exports->expects($this->once())->method('request')->with('123456782', 'bsn', null)->willReturn($export);

		$response = $this->controller(['subject' => ' 123456782 ', 'type' => ' bsn ', 'request' => '  '])->create();

		$this->assertSame([200, $export->jsonSerialize()], [$response->getStatus(), $response->getData()]);
	}//end testARequestPassesTheTrimmedFields()

	public function testANumericSubjectIsCastToText(): void {
		$this->exports->expects($this->once())->method('request')->with('42', null, '7')->willReturn($this->export());

		$this->controller(['subject' => 42, 'request' => 7])->create();
	}//end testANumericSubjectIsCastToText()

	public function testShowAnswers404OrTheState(): void {
		$export = $this->export();
		$this->exports->method('load')->willReturnMap([['export-1', $export], ['other', null]]);

		$found = $this->controller()->show('export-1');
		$missing = $this->controller()->show('other');

		$this->assertSame([200, $export->jsonSerialize()], [$found->getStatus(), $found->getData()]);
		$this->assertSame([404, ['error' => 'No subject export with that identifier exists.']], [$missing->getStatus(), $missing->getData()]);
	}//end testShowAnswers404OrTheState()

	public function testDownloadAnswers404OrTheFile(): void {
		$this->exports->method('download')->willReturnMap([['export-1', null, '{"a":1}'], ['other', null, null]]);

		$missing = $this->controller()->download('other');
		$file = $this->controller()->download('export-1');

		$this->assertSame(
			[404, ['error' => 'SUBJECT_EXPORT_UNAVAILABLE', 'message' => 'This export is not ready, has expired, or does not exist.']],
			[$missing->getStatus(), $missing->getData()]
		);
		$this->assertInstanceOf(DataDownloadResponse::class, $file);
		$this->assertSame(200, $file->getStatus());
		$this->assertSame('{"a":1}', $file->render());
		$headers = $file->getHeaders();
		$this->assertStringContainsString('subject-export-export-1.json', $headers['Content-Disposition']);
		$this->assertSame('application/json', $headers['Content-Type']);
	}//end testDownloadAnswers404OrTheFile()
}//end class
