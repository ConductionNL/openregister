<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\CompanyLookupController}.
 *
 * Covers the two free-text company-search endpoints: the 200 success relay of
 * the provider envelope, and the AD-23 degraded relay (`503` carrying
 * `details.cause`) when the OpenConnector source is missing or the upstream
 * register is down.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-kvk-opencorporates/specs/integration-company-lookup/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CompanyLookupController;
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CompanyLookupControllerTest.
 */
class CompanyLookupControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * KvK lookup leaf mock.
	 *
	 * @var KvkProvider&MockObject
	 */
	private KvkProvider&MockObject $kvk;

	/**
	 * OpenCorporates lookup leaf mock.
	 *
	 * @var OpenCorporatesProvider&MockObject
	 */
	private OpenCorporatesProvider&MockObject $openCorporates;

	/**
	 * Controller under test.
	 *
	 * @var CompanyLookupController
	 */
	private CompanyLookupController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->kvk = $this->createMock(KvkProvider::class);
		$this->openCorporates = $this->createMock(OpenCorporatesProvider::class);

		$this->controller = new CompanyLookupController(
			'openregister',
			$this->request,
			$this->kvk,
			$this->openCorporates
		);
	}//end setUp()

	/**
	 * Stub the request params from a simple map.
	 *
	 * @param array<string,mixed> $params The params the request should answer.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	public function testKvkSearchRelaysTheProviderEnvelope(): void {
		$this->withParams(['q' => 'Conduction', 'limit' => 5, 'page' => 2, 'plaats' => 'Utrecht']);

		$this->kvk->expects($this->once())
			->method('searchCompanies')
			->with('Conduction', ['plaats' => 'Utrecht'], 5, 2)
			->willReturn(
				[
					'results' => [['kvkNummer' => '12345678', 'naam' => 'Conduction B.V.']],
					'total' => 1,
					'limit' => 5,
					'page' => 2,
				]
			);

		$response = $this->controller->kvkSearch();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame('12345678', $data['results'][0]['kvkNummer']);
	}//end testKvkSearchRelaysTheProviderEnvelope()

	public function testKvkSearchRelaysADegradedProviderAs503WithCause(): void {
		$this->withParams(['q' => 'Conduction']);

		$this->kvk->method('searchCompanies')->willReturn(
			['unavailable' => true, 'cause' => 'openconnector-source-missing']
		);

		$response = $this->controller->kvkSearch();

		$this->assertSame(503, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('openconnector-source-missing', $data['details']['cause']);
		$this->assertStringContainsString('kvk', $data['error']);
	}//end testKvkSearchRelaysADegradedProviderAs503WithCause()

	public function testOpenCorporatesSearchForwardsTheJurisdictionAndRelaysResults(): void {
		$this->withParams(['q' => 'Acme', 'jurisdiction' => 'nl', 'limit' => 10, 'page' => 1]);

		$this->openCorporates->expects($this->once())
			->method('searchCompanies')
			->with('Acme', 'nl', 10, 1)
			->willReturn(['results' => [['name' => 'Acme N.V.']], 'total' => 1, 'limit' => 10, 'page' => 1]);

		$response = $this->controller->openCorporatesSearch();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Acme N.V.', $response->getData()['results'][0]['name']);
	}//end testOpenCorporatesSearchForwardsTheJurisdictionAndRelaysResults()

	public function testOpenCorporatesSearchRelaysADegradedProviderAs503WithCause(): void {
		$this->withParams(['q' => 'Acme']);

		$this->openCorporates->method('searchCompanies')->willReturn(
			['unavailable' => true, 'cause' => 'upstream-service-down']
		);

		$response = $this->controller->openCorporatesSearch();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('upstream-service-down', $response->getData()['details']['cause']);
	}//end testOpenCorporatesSearchRelaysADegradedProviderAs503WithCause()
}//end class
