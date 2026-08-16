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
use OCA\OpenRegister\Db\PhotoLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

class PhotosProvider extends AbstractIntegrationProvider {
	use MarkerLookupTrait;

	private const REQUIRED_APP = 'photos';

	private const MARKER_PREFIX = '[or:';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db NC DB connection.
	 * @param IAppManager $appManager NC app manager.
	 * @param IL10N $l10n Localisation.
	 * @param PhotoLinkMapper $photoLinkMapper Photo-link mapper (Tier-2 link table).
	 */
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IL10N $l10n,
		private PhotoLinkMapper $photoLinkMapper,
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

	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * List linked Photos albums for an OR object.
	 *
	 * Reads the Tier-2 link table first; if no link rows exist it falls
	 * back to the legacy `[or:{uuid}]` marker scan in `photos_albums.name`
	 * so albums that pre-date the link table still surface.
	 *
	 * @param string $register Register slug for the parent object.
	 * @param string $schema Schema slug for the parent object.
	 * @param string $objectId UUID of the OR object whose rows we want.
	 * @param array $filters Optional registry filters (unused).
	 *
	 * @return array<int,array<string,mixed>> List of registry leaf rows.
	 *
	 * @spec openspec/specs/integration-photos/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		// Tier-2 path: read from the link table.
		try {
			$linkRows = $this->photoLinkMapper->findByObjectUuid($objectId);
		} catch (Throwable $e) {
			$linkRows = [];
		}

		if (count($linkRows) > 0) {
			return array_map(
				fn (PhotoLink $link): array => $this->rowFromLink(link: $link),
				$linkRows
			);
		}

		// Backwards-compat fallback: scan the legacy `[or:{uuid}]`
		// marker in `photos_albums.name`.
		$marker = self::MARKER_PREFIX . $objectId . ']';
		$rows = $this->findByMarker(
			db: $this->db,
			table: 'photos_albums',
			markerColumn: 'name',
			marker: $marker,
			extraColumns: ['user', 'created'],
			idColumn: 'album_id',
		);

		return array_map(
			static function (array $row): array {
				return [
					'id' => (string)($row['album_id'] ?? ''),
					'title' => (string)($row['name'] ?? ''),
					'url' => '/index.php/apps/photos/albums/' . (string)($row['album_id'] ?? ''),
					'data' => $row,
				];
			},
			$rows
		);
	}//end list()

	/**
	 * Convert a PhotoLink row into the registry leaf-row shape.
	 *
	 * @param PhotoLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	private function rowFromLink(PhotoLink $link): array {
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
		$available = $this->isEnabled();
		$status = 'unavailable';
		$message = 'NC Photos app is not installed';
		if ($available === true) {
			$status = 'ok';
			$message = null;
		}

		return [
			'status' => $status,
			'authStatus' => 'configured',
			'message' => $message,
		];
	}//end health()
}//end class
