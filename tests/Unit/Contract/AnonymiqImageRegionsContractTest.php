<?php

/**
 * Cross-app contract: anonymiq's image regions request and response.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Service\File\AnonymiqRegionDetector;
use OCA\OpenRegister\Service\File\ExAppJsonRequester;
use OCA\OpenRegister\Service\File\IImageRegionDetector;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the shape both sides keep: request `{image, page}` to
 * `POST /api/v1/image/regions`, response `{regions: [{page, box: {x, y, w, h},
 * entityType, confidence}], detector: {name, version}}`. anonymiq's
 * object-detection change carries the matching test on its side.
 *
 * @covers \OCA\OpenRegister\Service\File\AnonymiqRegionDetector
 * @uses   \OCA\OpenRegister\Service\File\ImageRegion
 * @uses   \OCA\OpenRegister\Service\File\OcrWords
 */
final class AnonymiqImageRegionsContractTest extends TestCase {

	public function testTheRouteAndAppAreFixed(): void {
		$this->assertSame('anonymiq', AnonymiqRegionDetector::APP_ID);
		$this->assertSame('/api/v1/image/regions', AnonymiqRegionDetector::ROUTE);
	}//end testTheRouteAndAppAreFixed()

	public function testTheSeamSignatureIsFixed(): void {
		$method = new ReflectionMethod(IImageRegionDetector::class, 'detect');

		$this->assertSame(['imageBytes', 'page'], array_map(static fn ($param) => $param->getName(), $method->getParameters()));
		$this->assertSame('string', (string)$method->getParameters()[0]->getType());
		$this->assertSame('?int', (string)$method->getParameters()[1]->getType());
		$this->assertSame('array', (string)$method->getReturnType());
	}//end testTheSeamSignatureIsFixed()

	public function testTheRequestCarriesExactlyImageAndPage(): void {
		$requester = $this->createMock(ExAppJsonRequester::class);
		$requester->expects($this->once())
			->method('request')
			->with('anonymiq', '/api/v1/image/regions', $this->callback(static fn (array $body): bool => array_keys($body) === ['image', 'page'] && base64_decode($body['image'], true) === 'img' && $body['page'] === 1))
			->willReturn(['regions' => [], 'detector' => ['name' => 'anonymiq', 'version' => '1.0.0']]);

		$answer = (new AnonymiqRegionDetector($this->createMock(IAppManager::class), $requester))->detect('img', 1);

		$this->assertSame(['regions', 'detector'], array_keys($answer));
	}//end testTheRequestCarriesExactlyImageAndPage()

	public function testTheResponseShapeIsReadKeyForKey(): void {
		$region = ['page' => 1, 'box' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1], 'entityType' => 'FACE', 'confidence' => 0.8];
		$requester = $this->createMock(ExAppJsonRequester::class);
		$requester->method('request')->willReturn(['regions' => [$region], 'detector' => ['name' => 'anonymiq', 'version' => '1.0.0']]);

		$answer = (new AnonymiqRegionDetector($this->createMock(IAppManager::class), $requester))->detect('img', 1);

		$this->assertSame(['page', 'box', 'entityType', 'confidence', 'source', 'relationId'], array_keys($answer['regions'][0]));
		$this->assertSame(['x', 'y', 'w', 'h'], array_keys($answer['regions'][0]['box']));
	}//end testTheResponseShapeIsReadKeyForKey()
}//end class
