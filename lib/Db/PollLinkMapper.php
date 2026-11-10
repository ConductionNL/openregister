<?php

/**
 * Mapper for poll link entities.
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
 * Class PollLinkMapper
 *
 * @template-extends QBMapper<PollLink>
 * @method list<PollLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class PollLinkMapper extends QBMapper implements PollLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_poll_links', entityClass: PollLink::class);
	}//end __construct()

	/**
	 * Find a specific poll link by object UUID and poll id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pollId The poll id.
	 *
	 * @return PollLink|null The link or null if not found.
	 */
	public function findByObjectAndPoll(string $objectUuid, int $pollId): ?PollLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'poll_id', value: $pollId));
		if ($link instanceof PollLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndPoll()

	/**
	 * Delete a poll link by object UUID + poll id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pollId The poll id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndPoll(string $objectUuid, int $pollId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'poll_id', value: $pollId));
	}//end deleteByObjectAndPoll()
}//end class
