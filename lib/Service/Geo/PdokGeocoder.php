<?php

/**
 * PdokGeocoder — forward / reverse geocoding via PDOK Locatieserver.
 *
 * Implements REQ-GEO-005. Calls the PDOK Locatieserver
 * (`https://api.pdok.nl/bzk/locatieserver/search/v3_1/`) through
 * OpenConnector per ADR-022 (apps consume OR/OC abstractions — no
 * bespoke HTTP client). When OpenConnector is unavailable or the
 * upstream errors, geocoding degrades gracefully: forward geocoding
 * returns an empty suggestion list and reverse geocoding returns null.
 * It NEVER throws and NEVER blocks an object save (REQ-GEO-005).
 *
 * The actual transport is injected as a callable so the response-shaping
 * logic stays pure and unit-testable without OpenConnector present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Geo;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Geocoder that wraps the PDOK Locatieserver via OpenConnector.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *   Coupling to NC's IAppManager + the DI container is required to
 *   detect and lazily resolve the OpenConnector CallService.
 */
class PdokGeocoder {

	/**
	 * PDOK Locatieserver v3.1 base URL (REQ-GEO-005).
	 */
	public const LOCATIESERVER_BASE = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1';

	/**
	 * Optional transport override: `fn(string $url, array $params): ?array`.
	 *
	 * Injected in tests so response shaping is exercised without a live
	 * OpenConnector. Null in production — the OpenConnector CallService
	 * is resolved lazily.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager NC app manager — detects whether
	 *                                OpenConnector is enabled.
	 * @param ContainerInterface $container DI container — lazily resolves
	 *                                      OpenConnector's CallService.
	 * @param LoggerInterface $logger Logger for graceful-degrade traces.
	 * @param callable|null $transport Optional transport override (tests).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		?callable $transport = null,
	) {
		$this->transport = $transport;

	}//end __construct()

	/**
	 * Whether geocoding is currently available (OpenConnector enabled).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	public function isAvailable(): bool {
		if ($this->transport !== null) {
			return true;
		}

		try {
			return $this->appManager->isInstalled('openconnector');
		} catch (Throwable $e) {
			return false;
		}

	}//end isAvailable()

	/**
	 * Forward geocoding: free-text address query -> up to N suggestions.
	 *
	 * Each suggestion is `{ display, type, lon, lat, bagId }`. On any
	 * failure (OpenConnector down, upstream error, malformed payload)
	 * an empty list is returned — geocoding is non-blocking (REQ-GEO-005).
	 *
	 * @param string $query The free-text address.
	 * @param int $maxItems Maximum suggestions to return.
	 * @param bool $bagOnly Restrict to BAG `type:adres` results.
	 *
	 * @return array<int, array<string, mixed>> Suggestions (possibly empty).
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-006
	 */
	public function geocodeFree(string $query, int $maxItems = 5, bool $bagOnly = false): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}

		$params = [
			'q' => $query,
			'rows' => max(1, $maxItems),
		];
		if ($bagOnly === true) {
			$params['fq'] = 'type:adres';
		}

		$payload = $this->request(endpoint: 'free', params: $params);
		if ($payload === null) {
			return [];
		}

		return $this->shapeSuggestions(payload: $payload, maxItems: $maxItems);
	}//end geocodeFree()

	/**
	 * Reverse geocoding: coordinates -> nearest address (or null).
	 *
	 * @param float $longitude WGS84 longitude.
	 * @param float $latitude WGS84 latitude.
	 *
	 * @return array<string, mixed>|null The nearest address, or null.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	public function reverseGeocode(float $longitude, float $latitude): ?array {
		$payload = $this->request(
			endpoint: 'reverse',
			params: [
				'lon' => $longitude,
				'lat' => $latitude,
				'rows' => 1,
			]
		);
		if ($payload === null) {
			return null;
		}

		$suggestions = $this->shapeSuggestions(payload: $payload, maxItems: 1);
		return ($suggestions[0] ?? null);
	}//end reverseGeocode()

	/**
	 * Shape a raw Locatieserver payload into a suggestion list.
	 *
	 * Pure: tolerant of the Solr-style `response.docs` envelope PDOK
	 * returns. Missing/garbled docs yield an empty list.
	 *
	 * @param array $payload The decoded Locatieserver payload.
	 * @param int $maxItems Maximum suggestions.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	public function shapeSuggestions(array $payload, int $maxItems): array {
		$docs = ($payload['response']['docs'] ?? ($payload['docs'] ?? null));
		if (is_array($docs) === false) {
			return [];
		}

		$suggestions = [];
		foreach ($docs as $doc) {
			if (is_array($doc) === false) {
				continue;
			}

			$coords = $this->parseCentroid(point: ($doc['centroide_ll'] ?? null));

			$suggestions[] = [
				'display' => (string)($doc['weergavenaam'] ?? ''),
				'type' => (string)($doc['type'] ?? ''),
				'lon' => $coords[0],
				'lat' => $coords[1],
				'bagId' => ($doc['nummeraanduiding_id'] ?? null),
			];

			if (count($suggestions) >= $maxItems) {
				break;
			}
		}//end foreach

		return $suggestions;
	}//end shapeSuggestions()

	/**
	 * Parse a PDOK `POINT(lon lat)` WKT centroid into `[lon, lat]`.
	 *
	 * @param mixed $point The `centroide_ll` value.
	 *
	 * @return array{0: ?float, 1: ?float}
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	private function parseCentroid(mixed $point): array {
		if (is_string($point) === false) {
			return [null, null];
		}

		if (preg_match('/POINT\(([-0-9.]+)\s+([-0-9.]+)\)/i', $point, $m) === 1) {
			return [(float)$m[1], (float)$m[2]];
		}

		return [null, null];
	}//end parseCentroid()

	/**
	 * Perform a Locatieserver request, degrading gracefully to null.
	 *
	 * @param string $endpoint The Locatieserver endpoint (`free`/`reverse`).
	 * @param array $params Query parameters.
	 *
	 * @return array|null The decoded payload, or null on any failure.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	private function request(string $endpoint, array $params): ?array {
		$url = (self::LOCATIESERVER_BASE . '/' . $endpoint);

		if ($this->transport !== null) {
			try {
				$result = ($this->transport)($url, $params);
				if (is_array($result) === true) {
					return $result;
				}

				return null;
			} catch (Throwable $e) {
				$this->logger->warning('PDOK geocoding transport failed: ' . $e->getMessage());
				return null;
			}
		}

		if ($this->isAvailable() === false) {
			$this->logger->info('PDOK geocoding skipped: OpenConnector not available');
			return null;
		}

		try {
			$callService = $this->container->get('OCA\\OpenConnector\\Service\\CallService');
			$response = $callService->call(null, $url, 'GET', ['query' => $params]);
			return $this->decode(response: $response);
		} catch (Throwable $e) {
			$this->logger->warning('PDOK geocoding via OpenConnector failed: ' . $e->getMessage());
			return null;
		}

	}//end request()

	/**
	 * Decode an OpenConnector CallService response into an array.
	 *
	 * @param mixed $response The CallService return value.
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	private function decode(mixed $response): ?array {
		if (is_array($response) === true) {
			return $response;
		}

		$body = null;
		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$resp = $response->getResponse();
			$body = ($resp['body'] ?? null);
		}

		if (is_string($response) === true) {
			$body = $response;
		}

		if (is_string($body) === false) {
			return null;
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decode()
}//end class
