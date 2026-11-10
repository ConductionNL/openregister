<?php

/**
 * Golden coverage for how PdokGeocoder performs one Locatieserver request.
 *
 * Pins the URL and parameters handed to the transport, what a non-array or a
 * throwing transport degrades to, and what is logged when OpenConnector is
 * absent or its call service cannot be loaded.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Geo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Geo;

use OCA\OpenRegister\Service\Geo\PdokGeocoder;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks every branch of one geocoding request.
 */
class PdokGeocoderRequestGoldenTest extends TestCase {

	/**
	 * A geocoder with the given transport, logger and install state.
	 *
	 * @param callable|null   $transport The transport override, or null.
	 * @param LoggerInterface $logger    The logger.
	 *
	 * @return PdokGeocoder The geocoder.
	 */
	private function geocoder(?callable $transport, LoggerInterface $logger): PdokGeocoder {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new PdokGeocoder(
			appManager: $appManager,
			container: $this->createMock(ContainerInterface::class),
			logger: $logger,
			transport: $transport
		);
	}//end geocoder()

	/**
	 * The transport gets the endpoint URL and the query parameters, and its array is used.
	 *
	 * @return void
	 */
	public function testTheTransportReceivesTheEndpointUrlAndParameters(): void {
		$calls = [];
		$transport = function (string $url, array $params) use (&$calls): array {
			$calls[] = [$url, $params];
			return ['response' => ['docs' => [['weergavenaam' => 'Markt 1, Delft', 'type' => 'adres']]]];
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$found = $this->geocoder($transport, $logger)->reverseGeocode(4.5, 52.25);

		$this->assertSame('Markt 1, Delft', $found['display']);
		$this->assertCount(1, $calls);
		$this->assertStringEndsWith('/reverse', $calls[0][0]);
		$this->assertStringStartsWith('https://', $calls[0][0]);
		$this->assertSame(['lon' => 4.5, 'lat' => 52.25, 'rows' => 1], $calls[0][1]);
	}//end testTheTransportReceivesTheEndpointUrlAndParameters()

	/**
	 * A transport returning a non-array degrades to nothing, silently.
	 *
	 * @return void
	 */
	public function testANonArrayTransportResultDegradesSilently(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('info');

		$this->assertNull($this->geocoder(fn ($url, $params) => 'not json', $logger)->reverseGeocode(5.0, 52.0));
	}//end testANonArrayTransportResultDegradesSilently()

	/**
	 * A throwing transport degrades to nothing with a warning naming the failure.
	 *
	 * @return void
	 */
	public function testAThrowingTransportDegradesWithAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('PDOK geocoding transport failed: upstream down');

		$transport = function ($url, $params) {
			throw new RuntimeException('upstream down');
		};

		$this->assertSame([], $this->geocoder($transport, $logger)->geocodeFree('Markt 1'));
	}//end testAThrowingTransportDegradesWithAWarning()

	/**
	 * Without OpenConnector the request is skipped with an info line.
	 *
	 * @return void
	 */
	public function testAMissingOpenConnectorSkipsWithAnInfoLine(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('PDOK geocoding skipped: OpenConnector not available');

		$geocoder = new PdokGeocoder(
			appManager: $appManager,
			container: $this->createMock(ContainerInterface::class),
			logger: $logger
		);

		$this->assertNull($geocoder->reverseGeocode(5.0, 52.0));
	}//end testAMissingOpenConnectorSkipsWithAnInfoLine()

	/**
	 * With OpenConnector installed but its call service not loadable, the request is skipped.
	 *
	 * @return void
	 */
	public function testAnUnloadableCallServiceSkipsWithAnInfoLine(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('PDOK geocoding skipped: OpenConnector CallService not loadable');
		$logger->expects($this->never())->method('warning');

		$geocoder = new PdokGeocoder(appManager: $appManager, container: $container, logger: $logger);

		$this->assertSame([], $geocoder->geocodeFree('Markt 1'));
	}//end testAnUnloadableCallServiceSkipsWithAnInfoLine()
}//end class
