<?php

/**
 * OasControllerGoldenTest
 *
 * Golden test pinning the shared OAS generation path: the strict and
 * validate query flags, the 422 strict-mode report, and the ETag
 * short-circuit.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/oas-generation/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Service\Oas\OasIssue;
use OCA\OpenRegister\Controller\OasController;
use OCA\OpenRegister\Controller\Support\OasControllerServices;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\Oas\OasETagComputer;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCA\OpenRegister\Service\OasService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\OasController
 */
class OasControllerGoldenTest extends TestCase {

	private const SPEC = ['openapi' => '3.1.0', 'info' => ['title' => 'Golden', 'version' => '1']];

	private OasService&MockObject $oasService;

	private IRequest&MockObject $request;

	/**
	 * @var array<string, mixed>
	 */
	private array $params = [];

	private string $ifNoneMatch = '';

	protected function setUp(): void {
		$this->oasService = $this->createMock(OasService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $name, $default = null) => $this->params[$name] ?? $default
		);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name) => $name === 'IF_NONE_MATCH' ? $this->ifNoneMatch : ''
		);
	}

	private function controller(bool $withETag): OasController {
		$etag = null;
		if ($withETag === true) {
			$etag = new OasETagComputer();
		}

		return new OasController($this->request, new OasControllerServices($this->oasService, $etag));
	}

	private function report(): OasValidationReport {
		$report = new OasValidationReport();
		$report->addError(issue: new OasIssue(path: '#/paths', message: 'broken', code: OasValidationReport::CODE_DANGLING_REF));
		$report->addWarning(issue: new OasIssue(path: '#/tags', message: 'unused', code: OasValidationReport::CODE_UNUSED_TAG));

		return $report;
	}

	public function testStrictFlagAcceptsTruthySpellingsCaseInsensitively(): void {
		$seen = [];
		$this->oasService->method('createOas')->willReturnCallback(
			function (?string $registerId, bool $strict) use (&$seen): array {
				$seen[] = [$registerId, $strict];
				return self::SPEC;
			}
		);
		$controller = $this->controller(false);

		foreach (['TRUE', '1', 'Yes', 'on', 'false', '0', '', 'nope'] as $value) {
			$this->params = ['strict' => $value];
			$controller->generate('reg');
		}
		$this->params = [];
		$controller->generateAll();

		$this->assertSame(
			[
				['reg', true], ['reg', true], ['reg', true], ['reg', true],
				['reg', false], ['reg', false], ['reg', false], ['reg', false],
				[null, false],
			],
			$seen
		);
	}

	public function testStrictRejectionIsA422CarryingTheSummary(): void {
		$report = $this->report();
		$this->params = ['strict' => 'true'];
		$this->oasService->method('createOas')->willThrowException(
			new OasValidationException('OAS failed strict validation', $report)
		);

		$response = $this->controller(true)->generate('reg');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			['error' => 'OAS failed strict validation', 'summary' => $report->toSummary()],
			$response->getData()
		);
		$this->assertSame(2, count($response->getData()['summary']['issues']));
	}

	public function testGenericFailureIsA500WithTheMessage(): void {
		$this->oasService->method('createOas')->willThrowException(new Exception('boom'));

		$response = $this->controller(true)->generateAll();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'boom'], $response->getData());
	}

	public function testValidateAddsTheSummaryAndSkipsTheETag(): void {
		$report = $this->report();
		$this->params = ['validate' => 'yes'];
		$this->ifNoneMatch = '*';
		$this->oasService->method('createOas')->willReturn(self::SPEC);
		$this->oasService->method('getLastValidationReport')->willReturn($report);

		$response = $this->controller(true)->generate('reg');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::SPEC + ['x-validation-summary' => $report->toSummary()], $response->getData());
		$this->assertArrayNotHasKey('ETag', $response->getHeaders());
	}

	public function testWithoutAComputerThereIsNoETag(): void {
		$this->ifNoneMatch = '*';
		$this->oasService->method('createOas')->willReturn(self::SPEC);
		$this->oasService->expects($this->never())->method('getLastValidationReport');

		$response = $this->controller(false)->generate('reg');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::SPEC, $response->getData());
		$this->assertArrayNotHasKey('ETag', $response->getHeaders());
	}

	public function testETagIsSentWithTheBodyWhenNothingMatches(): void {
		$etag = (new OasETagComputer())->computeETag(self::SPEC);
		$this->oasService->method('createOas')->willReturn(self::SPEC);
		$controller = $this->controller(true);

		foreach (['', '"other"', 'W/"other", "else"'] as $ifNoneMatch) {
			$this->ifNoneMatch = $ifNoneMatch;
			$response = $controller->generate('reg');
			$this->assertSame(200, $response->getStatus());
			$this->assertSame(self::SPEC, $response->getData());
			$this->assertSame($etag, $response->getHeaders()['ETag']);
		}
	}

	public function testMatchingIfNoneMatchIsA304WithoutBody(): void {
		$etag = (new OasETagComputer())->computeETag(self::SPEC);
		$this->oasService->method('createOas')->willReturn(self::SPEC);
		$controller = $this->controller(true);

		foreach (['*', $etag, 'W/' . $etag, '"x", ' . $etag] as $ifNoneMatch) {
			$this->ifNoneMatch = $ifNoneMatch;
			$response = $controller->generateAll();
			$this->assertSame(304, $response->getStatus());
			$this->assertNull($response->getData());
			$this->assertSame($etag, $response->getHeaders()['ETag']);
		}
	}
}
