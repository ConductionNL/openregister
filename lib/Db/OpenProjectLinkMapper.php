<?php

/**
 * Mapper for OpenProject link entities.
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
 * Class OpenProjectLinkMapper
 *
 * @template-extends QBMapper<OpenProjectLink>
 * @method list<OpenProjectLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class OpenProjectLinkMapper extends QBMapper implements OpenProjectLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_openproject_links', entityClass: OpenProjectLink::class);
	}//end __construct()

	/**
	 * Find a specific OpenProject link by object UUID and work-package id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $workPackageId The work-package id.
	 *
	 * @return OpenProjectLink|null The link or null if not found.
	 */
	public function findByObjectAndWorkPackage(string $objectUuid, int $workPackageId): ?OpenProjectLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'work_package_id', value: $workPackageId));
		if ($link instanceof OpenProjectLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndWorkPackage()

	/**
	 * Delete an OpenProject link by object UUID + work-package id (Tier-2
	 * unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $workPackageId The work-package id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndWorkPackage(string $objectUuid, int $workPackageId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'work_package_id', value: $workPackageId));
	}//end deleteByObjectAndWorkPackage()
}//end class
