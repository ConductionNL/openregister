<?php

/**
 * Mapper for flow link entities.
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
 * Class FlowLinkMapper
 *
 * @template-extends QBMapper<FlowLink>
 * @method list<FlowLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class FlowLinkMapper extends QBMapper implements FlowLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_links', entityClass: FlowLink::class);
	}//end __construct()

	/**
	 * Find a specific flow link by object UUID and operation id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $operationId The operation id.
	 *
	 * @return FlowLink|null The link or null if not found.
	 */
	public function findByObjectAndOperation(string $objectUuid, int $operationId): ?FlowLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'operation_id', value: $operationId));
		if ($link instanceof FlowLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndOperation()

	/**
	 * Delete a flow link by object UUID + operation id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $operationId The operation id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndOperation(string $objectUuid, int $operationId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'operation_id', value: $operationId));
	}//end deleteByObjectAndOperation()
}//end class
