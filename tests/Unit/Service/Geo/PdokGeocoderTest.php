<?php

/**
 * Unit tests for PdokGeocoder — geocoding + graceful degradation.
 *
 * Closes geo-metadata-kaart REQ-GEO-005: forward / reverse geocoding via
 * the PDOK Locatieserver and non-blocking graceful degradation when
 * OpenConnector / PDOK is unavailable.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Geo;

use OCA\OpenRegister\Service\Geo\PdokGeocoder;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PdokGeocoderTest extends TestCase {

	private function locatieserverPayload(): array {
		return [
			'response' => [
				'docs' => [
					[
						'weergavenaam' => 'Keizersgracht 123, 1015 CJ Amsterdam',
						'type' => 'adres',
						'centroide_ll' => 'POINT(4.8842 52.3741)',
						'nummeraanduiding_id' => '0363200000123456',
					],
				],
			],
		];
	}//end locatieserverPayload()

	private function geocoder(?callable $transport, bool $ocInstalled = true): PdokGeocoder {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($ocInstalled);

		return new PdokGeocoder(
			appManager: $appManager,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			transport: $transport
		);
	}//end geocoder()

	public function testForwardGeocodingShapesSuggestions(): void {
		$payload = $this->locatieserverPayload();
		$geocoder = $this->geocoder(fn ($url, $params) => $payload);
		$suggested = $geocoder->geocodeFree('Keizersgracht 123 Amsterdam');

		$this->assertCount(1, $suggested);
		$this->assertSame('adres', $suggested[0]['type']);
		$this->assertSame(4.8842, $suggested[0]['lon']);
		$this->assertSame(52.3741, $suggested[0]['lat']);
		$this->assertSame('0363200000123456', $suggested[0]['bagId']);
	}//end testForwardGeocodingShapesSuggestions()

	public function testForwardGeocodingHonoursMaxItems(): void {
		$docs = [];
		for ($i = 0; $i < 10; $i++) {
			$docs[] = ['weergavenaam' => "Adres {$i}", 'type' => 'adres', 'centroide_ll' => 'POINT(5.0 52.0)'];
		}

		$geocoder = $this->geocoder(fn ($url, $params) => ['response' => ['docs' => $docs]]);
		$this->assertCount(3, $geocoder->geocodeFree('test', 3));
	}//end testForwardGeocodingHonoursMaxItems()

	public function testReverseGeocodingReturnsNearestAddress(): void {
		$geocoder = $this->geocoder(fn ($url, $params) => $this->locatieserverPayload());
		$address = $geocoder->reverseGeocode(4.8842, 52.3741);
		$this->assertNotNull($address);
		$this->assertStringContainsString('Keizersgracht', $address['display']);
	}//end testReverseGeocodingReturnsNearestAddress()

	public function testEmptyQueryReturnsEmptyWithoutCalling(): void {
		$called = false;
		$geocoder = $this->geocoder(function ($url, $params) use (&$called) {
			$called = true;
			return [];
		});
		$this->assertSame([], $geocoder->geocodeFree('   '));
		$this->assertFalse($called);
	}//end testEmptyQueryReturnsEmptyWithoutCalling()

	public function testGracefulDegradationWhenOpenConnectorMissing(): void {
		// No transport override + OpenConnector not installed -> not available,
		// forward geocoding returns [] and reverse returns null, no throw.
		$geocoder = $this->geocoder(null, false);
		$this->assertFalse($geocoder->isAvailable());
		$this->assertSame([], $geocoder->geocodeFree('Markt 1 Delft'));
		$this->assertNull($geocoder->reverseGeocode(5.0, 52.0));
	}//end testGracefulDegradationWhenOpenConnectorMissing()

	public function testGracefulDegradationOnTransportFailure(): void {
		// Transport throws -> degrade to empty / null, never propagate.
		$geocoder = $this->geocoder(function ($url, $params) {
			throw new RuntimeException('PDOK upstream down');
		});
		$this->assertSame([], $geocoder->geocodeFree('Markt 1 Delft'));
		$this->assertNull($geocoder->reverseGeocode(5.0, 52.0));
	}//end testGracefulDegradationOnTransportFailure()

	public function testGarbledPayloadDegradesToEmpty(): void {
		$geocoder = $this->geocoder(fn ($url, $params) => ['unexpected' => 'shape']);
		$this->assertSame([], $geocoder->geocodeFree('test'));
	}//end testGarbledPayloadDegradesToEmpty()

	public function testIsAvailableTrueWithTransportOverride(): void {
		$geocoder = $this->geocoder(fn ($url, $params) => [], false);
		$this->assertTrue($geocoder->isAvailable());
	}//end testIsAvailableTrueWithTransportOverride()
}//end class
