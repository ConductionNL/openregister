<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\PersonLookupController}.
 *
 * Covers the BRP HaalCentraal person lookup: the required-`bsn` 400, the 200
 * relay of the provider envelope (including the Wet-BRP audit `meta`), and the
 * AD-23 degraded relay (`503` carrying `details.cause`).
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
 * @spec openspec/specs/integration-person-lookup/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\PersonLookupController;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PersonLookupControllerTest.
 */
class PersonLookupControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * BRP person lookup leaf mock.
	 *
	 * @var BrpPersonProvider&MockObject
	 */
	private BrpPersonProvider&MockObject $brp;

	/**
	 * Controller under test.
	 *
	 * @var PersonLookupController
	 */
	private PersonLookupController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->brp = $this->createMock(BrpPersonProvider::class);

		$this->controller = new PersonLookupController(
			'openregister',
			$this->request,
			$this->brp
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

	public function testBrpPersonRejectsAMissingBsnWith400(): void {
		$this->withParams([]);
		$this->brp->expects($this->never())->method('lookupByBsn');

		$response = $this->controller->brpPerson();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('bsn is required', $response->getData()['error']);
	}//end testBrpPersonRejectsAMissingBsnWith400()

	public function testBrpPersonRelaysTheProviderEnvelopeIncludingAuditMeta(): void {
		$this->withParams(['bsn' => ' 999993653 ']);

		$this->brp->expects($this->once())
			->method('lookupByBsn')
			->with('999993653')
			->willReturn(
				[
					'results' => [['burgerservicenummer' => '999993653']],
					'total' => 1,
					'meta' => ['correlationId' => 'abc-123', 'durationMs' => 42, 'status' => 200],
				]
			);

		$response = $this->controller->brpPerson();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame('abc-123', $data['meta']['correlationId']);
	}//end testBrpPersonRelaysTheProviderEnvelopeIncludingAuditMeta()

	public function testBrpPersonRelaysADegradedProviderAs503WithCause(): void {
		$this->withParams(['bsn' => '999993653']);

		$this->brp->method('lookupByBsn')->willReturn(
			['unavailable' => true, 'cause' => 'provider-auth']
		);

		$response = $this->controller->brpPerson();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('provider-auth', $response->getData()['details']['cause']);
	}//end testBrpPersonRelaysADegradedProviderAs503WithCause()
}//end class
