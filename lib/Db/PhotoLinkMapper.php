<?php

/**
 * Mapper for photo link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class PhotoLinkMapper
 *
 * @template-extends QBMapper<PhotoLink>
 * @method list<PhotoLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class PhotoLinkMapper extends QBMapper implements PhotoLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_photo_links', entityClass: PhotoLink::class);
	}//end __construct()

	/**
	 * Find a specific photo link by object UUID and album id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $albumId The album id.
	 *
	 * @return PhotoLink|null The link or null if not found.
	 */
	public function findByObjectAndAlbum(string $objectUuid, int $albumId): ?PhotoLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'album_id', value: $albumId));
		if ($link instanceof PhotoLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndAlbum()

	/**
	 * Delete a photo link by object UUID + album id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $albumId The album id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndAlbum(string $objectUuid, int $albumId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'album_id', value: $albumId));
	}//end deleteByObjectAndAlbum()
}//end class
