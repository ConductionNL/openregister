<?php

/**
 * Mapper for map link entities.
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
 * Class MapLinkMapper
 *
 * @template-extends QBMapper<MapLink>
 * @method list<MapLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class MapLinkMapper extends QBMapper implements MapLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_map_links', entityClass: MapLink::class);
	}//end __construct()

	/**
	 * Find a specific map link by object UUID and favorite id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $favoriteId The favorite id.
	 *
	 * @return MapLink|null The link or null if not found.
	 */
	public function findByObjectAndFavorite(string $objectUuid, int $favoriteId): ?MapLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'favorite_id', value: $favoriteId));
		if ($link instanceof MapLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndFavorite()

	/**
	 * Delete a map link by object UUID + favorite id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $favoriteId The favorite id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndFavorite(string $objectUuid, int $favoriteId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'favorite_id', value: $favoriteId));
	}//end deleteByObjectAndFavorite()
}//end class
