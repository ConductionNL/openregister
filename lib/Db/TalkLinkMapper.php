<?php

/**
 * Mapper for talk link entities.
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
 * Class TalkLinkMapper
 *
 * @template-extends QBMapper<TalkLink>
 * @method list<TalkLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class TalkLinkMapper extends QBMapper implements TalkLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_talk_links', entityClass: TalkLink::class);
	}//end __construct()

	/**
	 * Find a specific talk link by object UUID and room token.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $roomToken The Talk room token.
	 *
	 * @return TalkLink|null The link or null if not found.
	 */
	public function findByObjectAndRoom(string $objectUuid, string $roomToken): ?TalkLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'room_token', value: $roomToken));
		if ($link instanceof TalkLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndRoom()

	/**
	 * Delete a talk link by object UUID + room token (Tier-2 unlink
	 * path — does NOT destroy the Talk room itself).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $roomToken The Talk room token.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndRoom(string $objectUuid, string $roomToken): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'room_token', value: $roomToken));
	}//end deleteByObjectAndRoom()
}//end class
