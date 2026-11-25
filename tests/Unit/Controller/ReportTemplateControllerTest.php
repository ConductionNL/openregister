<?php

/**
 * Tests for ReportTemplateController: POST /api/reports/generate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Controller\ReportTemplateController;
use OCA\OpenRegister\Search\Controller\Support\ReportGenerationFailures;
use OCA\OpenRegister\Search\Controller\Support\ReportsControllerServices;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCA\OpenRegister\Search\Service\Reporting\ReportPeriod;
use OCA\OpenRegister\Search\Service\Reporting\ReportTemplateGenerator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ReportTemplateController::class)]
#[CoversClass(ReportPeriod::class)]
#[CoversClass(ReportGenerationFailures::class)]
class ReportTemplateControllerTest extends TestCase {

	private function controller(
		array $params,
		?\Throwable $generatorError = null,
		?\Throwable $findError = null,
		?array &$seen = null,
	): ReportTemplateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$finder = $this->createMock(MagicObjectFinderInterface::class);
		if ($findError !== null) {
			$finder->method('find')->willThrowException($findError);
		} else {
			$entity = new ObjectEntity();
			$entity->setObject(['name' => 'Jaarcijfers', 'sections' => [['key' => 't', 'type' => 'total']]]);
			$finder->method('find')->willReturn($entity);
		}

		$generator = $this->createMock(ReportTemplateGenerator::class);
		if ($generatorError !== null) {
			$generator->method('generate')->willThrowException($generatorError);
		} else {
			$generator->method('generate')->willReturnCallback(
				static function (array $template, ReportPeriod $period, string $format) use (&$seen): array {
					$seen = ['template' => $template, 'from' => $period->from, 'to' => $period->to, 'format' => $format];
					return ['mime' => 'application/pdf', 'filename' => 'jaarcijfers.pdf', 'bytes' => '%PDF'];
				}
			);
		}

		return new ReportTemplateController($request, $generator, new ReportsControllerServices(
			$this->createMock(ReportRenderService::class),
			$finder,
			$this->createMock(LoggerInterface::class)
		));
	}//end controller()

	public function testAYearRendersTheTemplateOverThatCalendarYear(): void {
		$seen = null;
		$response = $this->controller(['templateId' => 'abc', 'year' => '2026', 'format' => 'PDF'], null, null, $seen)->generate();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(['2026-01-01', '2026-12-31', 'pdf'], [$seen['from'], $seen['to'], $seen['format']]);
		$this->assertSame('Jaarcijfers', $seen['template']['name']);
	}//end testAYearRendersTheTemplateOverThatCalendarYear()

	public function testADateRangeIsTakenAsGiven(): void {
		$seen = null;
		$this->controller(['templateId' => '12', 'dateRange' => ['from' => '2026-03-01', 'to' => '2026-03-07']], null, null, $seen)->generate();

		$this->assertSame(['2026-03-01', '2026-03-07', 'pdf'], [$seen['from'], $seen['to'], $seen['format']]);
	}//end testADateRangeIsTakenAsGiven()

	public function testWithoutATemplateIdTheRequestIsRefused(): void {
		$response = $this->controller(['year' => '2026'])->generate();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
	}//end testWithoutATemplateIdTheRequestIsRefused()

	public function testWithoutAPeriodTheRequestIsRefused(): void {
		$this->assertSame(400, $this->controller(['templateId' => 'abc'])->generate()->getStatus());
	}//end testWithoutAPeriodTheRequestIsRefused()

	public function testATemplateTheCallerCannotSeeIsNotFound(): void {
		$response = $this->controller(['templateId' => 'abc', 'year' => '2026'], null, new DoesNotExistException('gone'))->generate();

		$this->assertSame(404, $response->getStatus());
	}//end testATemplateTheCallerCannotSeeIsNotFound()

	public function testASectionTheCallerMayNotReadIsForbidden(): void {
		$response = $this->controller(['templateId' => 'abc', 'year' => '2026'], new NotAuthorizedException('no read'))->generate();

		$this->assertSame(403, $response->getStatus());
	}//end testASectionTheCallerMayNotReadIsForbidden()

	public function testAMalformedTemplateIsABadRequest(): void {
		$response = $this->controller(['templateId' => 'abc', 'year' => '2026'], new InvalidArgumentException('no sections'))->generate();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'no sections'], $response->getData());
	}//end testAMalformedTemplateIsABadRequest()

	public function testAnUnexpectedFailureSaysNothingInternal(): void {
		$response = $this->controller(['templateId' => 'abc', 'year' => '2026'], new \LogicException('secret path'))->generate();

		$this->assertSame(500, $response->getStatus());
		$this->assertStringNotContainsString('secret', json_encode($response->getData()));
	}//end testAnUnexpectedFailureSaysNothingInternal()
}//end class
