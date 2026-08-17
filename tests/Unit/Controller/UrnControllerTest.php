<?php

/**
 * Contract tests for UrnController.
 *
 * Covers the URN address-translation endpoints:
 *  - GET  /api/urn/lookup?url=…  → lookup
 *  - POST /api/urn/bulk          → bulk
 *  - GET  /api/urn/resolve?urn=… → resolve (the mirror of lookup)
 *
 * `bulk` is reachable by every authenticated user and fans a single request
 * out into ~4 DB round-trips per URN, so the 1000-URN ceiling is a security
 * control rather than a nicety — it has its own test below.
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
 * @spec openspec/specs/urn-resource-addressing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\UrnController;
use OCA\OpenRegister\Service\UrnService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UrnControllerTest extends TestCase {
	/**
	 * A well-formed URN used across the fixtures.
	 *
	 * @var string
	 */
	private const URN = 'urn:nl-or:example:persons:natuurlijke-personen:0f7b1c1e-0000-4000-8000-000000000001';

	/**
	 * The canonical API URL that URN addresses.
	 *
	 * @var string
	 */
	private const URL = '/apps/openregister/api/objects/persons/natuurlijke-personen/0f7b1c1e-0000-4000-8000-000000000001';

	/**
	 * The controller under test.
	 *
	 * @var UrnController
	 */
	private UrnController $controller;

	/**
	 * The mocked HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked URN resolution service.
	 *
	 * @var UrnService&MockObject
	 */
	private UrnService&MockObject $urnService;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->urnService = $this->createMock(UrnService::class);

		$this->controller = new UrnController(
			'openregister',
			$this->request,
			$this->urnService
		);
	}

	public function testLookupRequiresAUrl(): void {
		$this->urnService->expects($this->never())->method('urnFromUrl');

		$result = $this->controller->lookup(null);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(400, $result->getStatus());
		$this->assertSame('url parameter is required', $result->getData()['error']);
	}

	public function testLookupRejectsAnEmptyUrl(): void {
		$this->urnService->expects($this->never())->method('urnFromUrl');

		$result = $this->controller->lookup('');

		$this->assertSame(400, $result->getStatus());
	}

	public function testLookupReturnsTheUrnForAnObjectUrl(): void {
		$this->urnService
			->expects($this->once())
			->method('urnFromUrl')
			->with(self::URL)
			->willReturn(self::URN);

		$result = $this->controller->lookup(self::URL);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['url' => self::URL, 'urn' => self::URN], $result->getData());
	}

	/**
	 * A URL that is not an OpenRegister object reference is a 404, not a 200
	 * with a null urn — the caller must be able to branch on the status.
	 *
	 * @return void
	 */
	public function testLookupReturns404WhenTheUrlIsNotAnObjectReference(): void {
		$this->urnService->method('urnFromUrl')->willReturn(null);

		$result = $this->controller->lookup('https://example.org/some/other/page');

		$this->assertSame(404, $result->getStatus());
		$this->assertSame('https://example.org/some/other/page', $result->getData()['url']);
		$this->assertArrayNotHasKey('urn', $result->getData());
	}

	public function testBulkRequiresAUrnsArray(): void {
		$this->urnService->expects($this->never())->method('resolveBulk');

		$result = $this->controller->bulk(null);

		$this->assertSame(400, $result->getStatus());
		$this->assertSame('urns array is required', $result->getData()['error']);
	}

	public function testBulkRejectsAnEmptyUrnsArray(): void {
		$this->urnService->expects($this->never())->method('resolveBulk');

		$result = $this->controller->bulk([]);

		$this->assertSame(400, $result->getStatus());
	}

	public function testBulkResolvesEveryUrnAndReportsTheCount(): void {
		$second = 'urn:nl-or:example:persons:natuurlijke-personen:0f7b1c1e-0000-4000-8000-000000000002';
		$resolved = [
			self::URN => self::URL,
			$second => null,
		];

		$this->urnService
			->expects($this->once())
			->method('resolveBulk')
			->with([self::URN, $second])
			->willReturn($resolved);

		$result = $this->controller->bulk([self::URN, $second]);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(2, $result->getData()['count']);
		$this->assertSame($resolved, $result->getData()['resolved']);
		// An unresolvable URN is reported as a null value, not dropped — the
		// caller can tell "not on this instance" from "never asked".
		$this->assertNull($result->getData()['resolved'][$second]);
	}

	/**
	 * The DoS ceiling. 1000 is accepted; 1001 is refused with 422 and the
	 * service is never reached.
	 *
	 * @return void
	 */
	public function testBulkRefusesMoreThanOneThousandUrns(): void {
		$this->urnService->expects($this->never())->method('resolveBulk');

		$result = $this->controller->bulk(array_fill(0, 1001, self::URN));

		$this->assertSame(422, $result->getStatus());
		$this->assertSame(1001, $result->getData()['count']);
	}

	public function testBulkAcceptsExactlyOneThousandUrns(): void {
		$this->urnService
			->expects($this->once())
			->method('resolveBulk')
			->willReturn(array_fill(0, 1000, self::URL));

		$result = $this->controller->bulk(array_fill(0, 1000, self::URN));

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(1000, $result->getData()['count']);
	}

	/**
	 * The mirror direction — kept alongside lookup so both halves of the
	 * address translation are pinned by the same file.
	 *
	 * @return void
	 */
	public function testResolveReturnsTheCanonicalUrlAndItsParts(): void {
		$parts = [
			'instance' => 'example',
			'register' => 'persons',
			'schema' => 'natuurlijke-personen',
			'uuid' => '0f7b1c1e-0000-4000-8000-000000000001',
		];

		$this->urnService->method('parse')->with(self::URN)->willReturn($parts);
		$this->urnService->method('resolveUrl')->with(self::URN)->willReturn(self::URL);

		$result = $this->controller->resolve(self::URN);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(self::URL, $result->getData()['url']);
		$this->assertSame('persons', $result->getData()['register']);
		$this->assertSame('natuurlijke-personen', $result->getData()['schema']);
	}

	public function testResolveReturns400ForAMalformedUrn(): void {
		$this->urnService->method('parse')->willReturn(null);
		$this->urnService->expects($this->never())->method('resolveUrl');

		$result = $this->controller->resolve('not-a-urn');

		$this->assertSame(400, $result->getStatus());
		$this->assertStringContainsString('malformed URN', $result->getData()['error']);
	}
}
