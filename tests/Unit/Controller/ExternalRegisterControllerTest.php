<?php

/**
 * Unit tests for ExternalRegisterController — every state of the read is a
 * 200 with a cache header; only an unreadable schema is a 404.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ExternalRegisterController;
use OCA\OpenRegister\Service\Integration\ExternalRegisterLookupInterface;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ExternalRegisterController
 */
final class ExternalRegisterControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private ExternalRegisterLookupInterface&MockObject $lookup;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->lookup  = $this->createMock(ExternalRegisterLookupInterface::class);
	}

	private function controller(): ExternalRegisterController {
		return new ExternalRegisterController($this->request, $this->lookup);
	}

	public function testAnAnswerIsA200CachedForItsState(): void {
		$answer = ['state' => 'unreachable', 'adminActionable' => true, 'record' => null, 'fetchedAt' => '2027-01-15T08:00:00Z', 'source' => 'bag', 'cacheSeconds' => 30];
		$this->request->method('getParam')->with('refresh', '')->willReturn('');
		$this->lookup->expects($this->once())->method('read')->with('4', 'bag-adres', 'k', false)->willReturn($answer);

		$response = $this->controller()->show('4', 'bag-adres', 'k');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
		$this->assertSame('private, max-age=30', $response->getHeaders()['Cache-Control']);
	}

	public function testRefreshIsPassedOn(): void {
		$this->request->method('getParam')->willReturn('1');
		$this->lookup->expects($this->once())->method('read')->with('4', 'bag-adres', 'k', true)
			->willReturn(['state' => 'ok', 'cacheSeconds' => 900]);

		$this->assertSame(200, $this->controller()->show('4', 'bag-adres', 'k')->getStatus());
	}

	public function testAnUnreadableSchemaIsA404ThatSaysNothingMore(): void {
		$this->request->method('getParam')->willReturn('');
		$this->lookup->method('read')->willReturn(null);

		$response = $this->controller()->show('4', 'secret', 'k');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Not found'], $response->getData());
	}
}
