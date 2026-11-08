<?php

/**
 * PhotosProvider — exposes NC Photos albums linked to an OpenRegister
 * object via the Tier-2 `openregister_photo_links` table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the album's `name` field. Tier-2 (this file) reads the dedicated
 * link table instead — the marker convention is retained as a
 * backwards-compat fallback for albums that pre-date the link table.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `photos_albums` table is only read for live cover / count
 * data via the wrapping {@see \OCA\OpenRegister\Service\PhotoLinkService}.
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

use OCA\OpenRegister\Db\PhotoLink;
use OCA\OpenRegister\Db\PhotoLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class PhotosProvider extends AbstractIntegrationProvider {

	use NcAppLinkTableProvider;
	use MarkerSourceFallback;
	use MarkerLookupTrait;

	private const REQUIRED_APP = 'photos';

	private const MARKER_PREFIX = '[or:';

	/**
	 * Where the legacy `[or:{uuid}]` marker lives: table, marker column, extra
	 * columns, id column, and the app URL a row's id is appended to.
	 *
	 * @var array{table: string, column: string, extra: list<string>, id: string, url: string}
	 */
	private const MARKER_SOURCE = [
		'table' => 'photos_albums',
		'column' => 'name',
		'extra' => ['user', 'created'],
		'id' => 'album_id',
		'url' => '/index.php/apps/photos/albums/',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db NC DB connection.
	 * @param IAppManager $appManager NC app manager.
	 * @param IL10N $l10n Localisation.
	 * @param PhotoLinkMapperInterface $photoLinkMapper Photo-link mapper (Tier-2 link table).
	 * @param LoggerInterface $logger Logger for the degraded marker-scan path.
	 */
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IL10N $l10n,
		private PhotoLinkMapperInterface $photoLinkMapper,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	public function getId(): string {
		return 'photos';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Photos');
	}//end getLabel()

	public function getIcon(): string {
		return 'Image';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'docs';
	}//end getGroup()

	/**
	 * The link mapper this provider reads first.
	 *
	 * @return PhotoLinkMapperInterface
	 */
	protected function linkMapper(): PhotoLinkMapperInterface {
		return $this->photoLinkMapper;
	}//end linkMapper()

	/**
	 * Convert a PhotoLink row into the registry leaf-row shape.
	 *
	 * @param PhotoLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromLink(PhotoLink $link): array {
		$albumId = (int)$link->getAlbumId();
		$data = $link->jsonSerialize();

		return [
			'id' => (string)$albumId,
			'title' => (string)$link->getAlbumName(),
			'url' => '/index.php/apps/photos/albums/' . $albumId,
			'coverPhotoUrl' => $link->getCoverPhotoUrl(),
			'photoCount' => $link->getPhotoCount(),
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
		return $this->installedHealth(available: $this->isEnabled(), missing: 'NC Photos app is not installed');
	}//end health()
}//end class
