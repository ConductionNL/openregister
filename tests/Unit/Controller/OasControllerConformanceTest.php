<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\OasController;
use OCA\OpenRegister\Controller\Support\OasControllerServices;
use OCA\OpenRegister\Service\Oas\NlGovConformanceReport;
use OCA\OpenRegister\Service\Oas\NlGovConformanceService;
use OCA\OpenRegister\Service\Oas\NlGovResponseProbe;
use OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCA\OpenRegister\Service\OasServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The conformance routes: two public reads and an administrator probe.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
#[CoversClass(OasController::class)]
#[CoversClass(NlGovConformanceService::class)]
#[UsesClass(OasControllerServices::class)]
#[UsesClass(NlGovConformanceReport::class)]
#[UsesClass(NlGovRuleCatalogue::class)]
#[UsesClass(OasValidationReport::class)]
class OasControllerConformanceTest extends TestCase {

	/**
	 * A controller over a mocked document generator and probe.
	 *
	 * @param OasServiceInterface $oas   The generator.
	 * @param NlGovResponseProbe  $probe The probe.
	 *
	 * @return OasController
	 */
	private function controller(OasServiceInterface $oas, NlGovResponseProbe $probe): OasController {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-24T12:00:00+00:00'));

		return new OasController(
			$this->createMock(IRequest::class),
			new OasControllerServices($oas, null, new NlGovConformanceService($oas, $probe, $time))
		);
	}

	/**
	 * A generator that answers with an empty validation report.
	 *
	 * @return OasServiceInterface
	 */
	private function generator(): OasServiceInterface {
		$oas = $this->createMock(OasServiceInterface::class);
		$oas->method('createOas')->willReturn(['servers' => [['url' => 'https://nc.example']], 'paths' => []]);
		$oas->method('getLastValidationReport')->willReturn(new OasValidationReport());
		return $oas;
	}

	public function testTheRegisterReportCarriesTheLastProbe(): void {
		$probe = $this->createMock(NlGovResponseProbe::class);
		$probe->expects($this->once())->method('lastResult')->with('7')->willReturn(
			['probedAt' => '2026-11-24T11:00:00+00:00', 'rules' => ['/core/version-header' => ['result' => 'fail', 'findings' => []]]]
		);
		$probe->expects($this->never())->method('probe');

		$response = $this->controller(oas: $this->generator(), probe: $probe)->conformance(id: '7');
		$data     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('2.2.1', $data['ruleset']);
		$this->assertSame('2026-11-24T12:00:00+00:00', $data['generatedAt']);
		$this->assertSame('2026-11-24T11:00:00+00:00', $data['probedAt']);
		$this->assertSame(2, $data['counts']['not-probed']);
	}

	public function testTheCombinedReportReadsNoProbe(): void {
		$probe = $this->createMock(NlGovResponseProbe::class);
		$probe->expects($this->never())->method('lastResult');

		$data = $this->controller(oas: $this->generator(), probe: $probe)->conformanceAll()->getData();

		$this->assertSame(3, $data['counts']['not-probed']);
	}

	public function testTheProbeRouteProbesTheRegister(): void {
		$probe = $this->createMock(NlGovResponseProbe::class);
		$probe->expects($this->once())->method('probe')->with('7', $this->arrayHasKey('paths'))->willReturn(
			['probedAt' => 'x', 'requests' => 2, 'rules' => ['/core/date-time/timezone' => ['result' => 'pass', 'findings' => []]]]
		);

		$data = $this->controller(oas: $this->generator(), probe: $probe)->probe(id: '7')->getData();

		$this->assertSame('pass', array_column($data['rules'], 'result', 'id')['/core/date-time/timezone']);
	}

	public function testAnUnknownRegisterIs404(): void {
		$oas = $this->createMock(OasServiceInterface::class);
		$oas->method('createOas')->willThrowException(new DoesNotExistException('none'));

		$response = $this->controller(oas: $oas, probe: $this->createMock(NlGovResponseProbe::class))->conformance(id: 'nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Register not found'], $response->getData());
	}

	public function testWithoutTheServiceTheReportIsUnavailable(): void {
		$controller = new OasController($this->createMock(IRequest::class), new OasControllerServices($this->generator()));

		$this->assertSame(503, $controller->conformance(id: '7')->getStatus());
	}

	public function testTheReadsArePublicAndRateLimitedAndTheProbeIsAdministratorOnly(): void {
		foreach (['conformance', 'conformanceAll'] as $method) {
			$reflection = new ReflectionMethod(OasController::class, $method);
			$this->assertStringContainsString('@PublicPage', (string) $reflection->getDocComment(), $method);
			$limits = $reflection->getAttributes(AnonRateLimit::class);
			$this->assertCount(1, $limits, $method);
			$this->assertSame(['limit' => 30, 'period' => 60], $limits[0]->getArguments(), $method);
		}

		$probe = (string) (new ReflectionMethod(OasController::class, 'probe'))->getDocComment();
		$this->assertStringNotContainsString('@NoAdminRequired', $probe);
		$this->assertStringNotContainsString('@PublicPage', $probe);
		$this->assertStringNotContainsString('@NoCSRFRequired', $probe);
	}
}
