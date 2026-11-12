<?php

/**
 * Mapper for collective link entities.
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
 * Class CollectiveLinkMapper
 *
 * @template-extends QBMapper<CollectiveLink>
 * @method list<CollectiveLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class CollectiveLinkMapper extends QBMapper implements CollectiveLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_collective_links', entityClass: CollectiveLink::class);
	}//end __construct()

	/**
	 * Find a specific collective link by object UUID and page id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pageId The page id.
	 *
	 * @return CollectiveLink|null The link or null if not found.
	 */
	public function findByObjectAndPage(string $objectUuid, int $pageId): ?CollectiveLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'page_id', value: $pageId));
		if ($link instanceof CollectiveLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndPage()

	/**
	 * Delete a collective link by object UUID + page id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pageId The page id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndPage(string $objectUuid, int $pageId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'page_id', value: $pageId));
	}//end deleteByObjectAndPage()
}//end class
