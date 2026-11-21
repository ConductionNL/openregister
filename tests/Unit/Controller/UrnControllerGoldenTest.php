<?php

/**
 * Golden tests for UrnController.
 *
 * Pins status and exact body of every branch of resolve, lookup and bulk
 * so a refactor of the controller can be proven behaviour-preserving.
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
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\UrnController;
use OCA\OpenRegister\Service\UrnService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for UrnController.
 */
class UrnControllerGoldenTest extends TestCase {
	private const URN = 'urn:nl-or:inst:reg:sch:abc';

	private const PARTS = ['instance' => 'inst', 'register' => 'reg', 'schema' => 'sch', 'uuid' => 'abc', 'extra' => 'x'];

	private UrnService&MockObject $urnService;
	private UrnController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->urnService = $this->createMock(UrnService::class);
		$this->controller = new UrnController($this->createMock(IRequest::class), $this->urnService);
	}

	public function testResolveMissingUrn(): void {
		foreach ([null, ''] as $urn) {
			$response = $this->controller->resolve($urn);
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'urn parameter is required'], $response->getData());
		}
	}

	public function testResolveMalformedUrn(): void {
		$this->urnService->expects($this->once())->method('parse')->with('bad')->willReturn(null);
		$this->urnService->expects($this->never())->method('resolveUrl');

		$response = $this->controller->resolve('bad');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['error' => 'malformed URN; expected urn:nl-or:<instance>:<register>:<schema>:<uuid>'],
			$response->getData()
		);
	}

	public function testResolveUnknownOnThisInstance(): void {
		$this->urnService->method('parse')->willReturn(self::PARTS);
		$this->urnService->expects($this->once())->method('resolveUrl')->with(self::URN)->willReturn(null);

		$response = $this->controller->resolve(self::URN);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(
			['error' => 'URN does not resolve on this instance', 'urn' => self::URN, 'parts' => self::PARTS],
			$response->getData()
		);
	}

	public function testResolveSuccessReturnsOnlyTheAddressingParts(): void {
		$this->urnService->method('parse')->willReturn(self::PARTS);
		$this->urnService->method('resolveUrl')->willReturn('https://h/api/objects/reg/sch/abc');

		$response = $this->controller->resolve(self::URN);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'urn' => self::URN,
				'url' => 'https://h/api/objects/reg/sch/abc',
				'instance' => 'inst',
				'register' => 'reg',
				'schema' => 'sch',
				'uuid' => 'abc',
			],
			$response->getData()
		);
	}

	public function testLookupMissingUrl(): void {
		foreach ([null, ''] as $url) {
			$response = $this->controller->lookup($url);
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'url parameter is required'], $response->getData());
		}
	}

	public function testLookupNotAnObjectReference(): void {
		$this->urnService->expects($this->once())->method('urnFromUrl')->with('https://x')->willReturn(null);

		$response = $this->controller->lookup('https://x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(
			['error' => 'URL is not an OpenRegister object reference', 'url' => 'https://x'],
			$response->getData()
		);
	}

	public function testLookupSuccess(): void {
		$this->urnService->method('urnFromUrl')->willReturn(self::URN);

		$response = $this->controller->lookup('https://x');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['url' => 'https://x', 'urn' => self::URN], $response->getData());
	}

	public function testBulkMissingOrEmpty(): void {
		foreach ([null, []] as $urns) {
			$response = $this->controller->bulk($urns);
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'urns array is required'], $response->getData());
		}
	}

	public function testBulkOverTheCap(): void {
		$this->urnService->expects($this->never())->method('resolveBulk');

		$response = $this->controller->bulk(array_fill(0, 1001, self::URN));

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Too many URNs (max 1000 per request)', 'count' => 1001], $response->getData());
	}

	public function testBulkAtTheCapResolves(): void {
		$urns = array_fill(0, 1000, self::URN);
		$this->urnService->expects($this->once())->method('resolveBulk')->with($urns)
			->willReturn([self::URN => 'https://u', 'urn:nl-or:i:r:s:z' => null]);

		$response = $this->controller->bulk($urns);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['count' => 2, 'resolved' => [self::URN => 'https://u', 'urn:nl-or:i:r:s:z' => null]],
			$response->getData()
		);
	}
}
