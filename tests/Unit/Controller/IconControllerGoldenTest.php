<?php

/**
 * Golden tests for IconController.
 *
 * Pins status, body and the full header set (bar the clock-derived
 * `Expires` value) of both mdi() outcomes so a refactor can be proven
 * behaviour-preserving.
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

use OCA\OpenRegister\Controller\IconController;
use OCA\OpenRegister\Service\MdiIconSvg;
use OCA\OpenRegister\Service\MdiIconRenderer;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for IconController.
 */
class IconControllerGoldenTest extends TestCase {
	private IconController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new IconController($this->createMock(IRequest::class), new MdiIconSvg());
	}

	public function testKnownIcon(): void {
		$response = $this->controller->mdi('mdi-cat');
		$headers = $response->getHeaders();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(MdiIconRenderer::svg(icon: 'mdi-cat'), $response->getData());
		$this->assertSame('image/svg+xml', $headers['Content-Type']);
		$this->assertSame('private, max-age=86400, immutable', $headers['Cache-Control']);
		$this->assertIsString($headers['Expires']);
		unset($headers['Expires']);
		$this->assertSame($headers, $this->referenceHeaders(status: 200, svg: true));
	}

	public function testUnknownIcon(): void {
		$response = $this->controller->mdi('no-such-icon-anywhere');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(404, $response->getStatus());
		$this->assertSame('', $response->getData());
		$this->assertSame($this->referenceHeaders(status: 404, svg: false), $response->getHeaders());
	}

	/**
	 * The headers a fresh DataDisplayResponse carries, plus what mdi() adds.
	 *
	 * @param int $status The status code.
	 * @param bool $svg Whether mdi() served a glyph.
	 *
	 * @return array<string, mixed>
	 */
	private function referenceHeaders(int $status, bool $svg): array {
		if ($svg === false) {
			return (new DataDisplayResponse('', $status))->getHeaders();
		}

		$headers = (new DataDisplayResponse('', $status, ['Content-Type' => 'image/svg+xml']))->getHeaders();
		$headers['Cache-Control'] = 'private, max-age=86400, immutable';

		return $headers;
	}
}
