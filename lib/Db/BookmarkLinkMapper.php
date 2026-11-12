<?php

/**
 * Mapper for bookmark link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class BookmarkLinkMapper
 *
 * @template-extends QBMapper<BookmarkLink>
 * @method list<BookmarkLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class BookmarkLinkMapper extends QBMapper implements BookmarkLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_bookmark_links', entityClass: BookmarkLink::class);
	}//end __construct()

	/**
	 * Find a specific bookmark link by object UUID and bookmark id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $bookmarkId The bookmark id.
	 *
	 * @return BookmarkLink|null The link or null if not found.
	 */
	public function findByObjectAndBookmark(string $objectUuid, int $bookmarkId): ?BookmarkLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'bookmark_id', value: $bookmarkId));
		if ($link instanceof BookmarkLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndBookmark()

	/**
	 * Delete a bookmark link by object UUID + bookmark id (Tier-2 unlink).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $bookmarkId The bookmark id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndBookmark(string $objectUuid, int $bookmarkId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'bookmark_id', value: $bookmarkId));
	}//end deleteByObjectAndBookmark()
}//end class
