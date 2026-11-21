<?php

/**
 * AnonymiqRegionDetector: anonymiq's image regions through the seam.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
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

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Service\File\AnonymiqRegionDetector;
use OCA\OpenRegister\Service\File\ExAppJsonRequester;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\AnonymiqRegionDetector
 * @uses   \OCA\OpenRegister\Service\File\ImageRegion
 * @uses   \OCA\OpenRegister\Service\File\OcrWords
 */
final class AnonymiqRegionDetectorTest extends TestCase {

	private IAppManager&MockObject $apps;

	private ExAppJsonRequester&MockObject $requester;

	protected function setUp(): void {
		$this->apps = $this->createMock(IAppManager::class);
		$this->requester = $this->createMock(ExAppJsonRequester::class);
	}//end setUp()

	private function detector(): AnonymiqRegionDetector {
		return new AnonymiqRegionDetector($this->apps, $this->requester);
	}//end detector()

	private static function recorded(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/anonymiq/image-regions-response.json'), true);
	}//end recorded()

	public function testRegionsFromTheExAppBecomeEntityRelations(): void {
		$this->requester->expects($this->once())
			->method('request')
			->with('anonymiq', '/api/v1/image/regions', ['image' => base64_encode('PNGBYTES'), 'page' => 2])
			->willReturn(self::recorded());

		$answer = $this->detector()->detect('PNGBYTES', 2);

		$this->assertSame(['name' => 'anonymiq', 'version' => '0.4.1'], $answer['detector']);
		$this->assertSame(
			[['page' => 2, 'box' => ['x' => 0.62, 'y' => 0.81, 'w' => 0.25, 'h' => 0.07], 'entityType' => 'SIGNATURE', 'confidence' => 0.93, 'source' => 'anonymiq 0.4.1', 'relationId' => null]],
			$answer['regions']
		);
	}//end testRegionsFromTheExAppBecomeEntityRelations()

	/**
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function malformed(): array {
		$detector = ['name' => 'anonymiq', 'version' => '1'];
		return [
			'no detector' => [['regions' => []], 'anonymiq answered without detector.name and detector.version.'],
			'version not a string' => [['regions' => [], 'detector' => ['name' => 'anonymiq', 'version' => 1]], 'anonymiq answered without detector.name and detector.version.'],
			'no regions' => [['detector' => $detector], 'anonymiq answered without a regions list.'],
			'regions an object' => [['detector' => $detector, 'regions' => ['a' => []]], 'anonymiq answered without a regions list.'],
			'box outside' => [['detector' => $detector, 'regions' => [['box' => ['x' => 0.9, 'y' => 0, 'w' => 0.5, 'h' => 0.1], 'entityType' => 'FACE', 'confidence' => 0.5]]], 'anonymiq region 0 is malformed: region.box reaches outside the image.'],
		];
	}//end malformed()

	/**
	 * @dataProvider malformed
	 */
	public function testAMalformedResponseIsRecordedAsFailed(array $answer, string $message): void {
		$this->requester->method('request')->willReturn($answer);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		$this->detector()->detect('x', null);
	}//end testAMalformedResponseIsRecordedAsFailed()

	public function testItIsInstalledOnlyWithAppApiAndAnonymiqEnabled(): void {
		$enabled = ['app_api' => true, 'anonymiq' => false];
		$this->apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $enabled[$app]);

		$this->assertFalse($this->detector()->isInstalled());
		$this->assertSame('anonymiq', $this->detector()->name());
	}//end testItIsInstalledOnlyWithAppApiAndAnonymiqEnabled()

	public function testWithBothEnabledItIsInstalled(): void {
		$this->apps->expects($this->exactly(2))->method('isEnabledForUser')->willReturn(true);

		$this->assertTrue($this->detector()->isInstalled());
	}//end testWithBothEnabledItIsInstalled()
}//end class
