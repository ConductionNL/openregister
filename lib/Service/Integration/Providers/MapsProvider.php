<?php

/**
 * MapsProvider — exposes NC Maps favorites (POIs) linked to an
 * OpenRegister object via the Tier-2 `openregister_map_links` table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the favorite's `name` field. Tier-2 (this file) reads the dedicated
 * link table instead — the marker convention is retained as a
 * backwards-compat fallback for favorites that pre-date the link table.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `maps_favorites` table is only read by the wrapping
 * {@see \OCA\OpenRegister\Service\MapLinkService} for picker / create
 * flows.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing

use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class MapsProvider extends AbstractIntegrationProvider {

	use NcAppLinkTableProvider;
	use MarkerSourceFallback;
	use MarkerLookupTrait;

	private const REQUIRED_APP = 'maps';

	private const MARKER_PREFIX = '[or:';

	/**
	 * Where the legacy `[or:{uuid}]` marker lives: table, marker column, extra
	 * columns, id column, and the app URL a row's id is appended to.
	 *
	 * @var array{table: string, column: string, extra: list<string>, id: string, url: string}
	 */
	private const MARKER_SOURCE = [
		'table' => 'maps_favorites',
		'column' => 'name',
		'extra' => ['lat', 'lng', 'category', 'comment'],
		'id' => 'id',
		'url' => '/index.php/apps/maps/#/m=',
	];

	/**
	 * NC DB connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Logger for the degraded marker-scan path.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param LeafMarkerScanning     $leaf          The DB connection, app manager, localisation and marker-scan logger.
	 * @param MapLinkMapperInterface $mapLinkMapper Map-link mapper (Tier-2 link table).
	 */
	public function __construct(
		LeafMarkerScanning $leaf,
		private MapLinkMapperInterface $mapLinkMapper,
	) {
		$this->db = $leaf->db;
		$this->appManager = $leaf->appManager;
		$this->l10n = $leaf->l10n;
		$this->logger = $leaf->logger;
	}//end __construct()

	public function getId(): string {
		return 'maps';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Locations');
	}//end getLabel()

	public function getIcon(): string {
		return 'MapMarker';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'docs';
	}//end getGroup()

	/**
	 * The link mapper this provider reads first.
	 *
	 * @return MapLinkMapperInterface
	 */
	protected function linkMapper(): MapLinkMapperInterface {
		return $this->mapLinkMapper;
	}//end linkMapper()

	/**
	 * Convert a MapLink row into the registry leaf-row shape.
	 *
	 * @param MapLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromLink(MapLink $link): array {
		$favoriteId = (int)$link->getFavoriteId();
		$data = $link->jsonSerialize();

		return [
			'id' => (string)$favoriteId,
			'title' => (string)$link->getName(),
			'url' => '/index.php/apps/maps/#/m=' . $favoriteId,
			'category' => $link->getCategory(),
			'lat' => $link->getLat(),
			'lng' => $link->getLng(),
			'data' => $data,
		];
	}//end rowFromLink()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *              the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->isEnabled(), missing: 'NC Location app is not installed');
	}//end health()
}//end class
