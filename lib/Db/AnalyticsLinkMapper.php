<?php

/**
 * Mapper for analytics link entities.
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
 * Class AnalyticsLinkMapper
 *
 * @template-extends QBMapper<AnalyticsLink>
 * @method list<AnalyticsLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class AnalyticsLinkMapper extends QBMapper implements AnalyticsLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_analytics_links', entityClass: AnalyticsLink::class);
	}//end __construct()

	/**
	 * Find a specific analytics link by object UUID and report id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $reportId The report id.
	 *
	 * @return AnalyticsLink|null The link or null if not found.
	 */
	public function findByObjectAndReport(string $objectUuid, int $reportId): ?AnalyticsLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'report_id', value: $reportId));
		if ($link instanceof AnalyticsLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndReport()

	/**
	 * Delete an analytics link by object UUID + report id (Tier-2 unlink
	 * path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $reportId The report id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndReport(string $objectUuid, int $reportId): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'report_id', value: $reportId));
	}//end deleteByObjectAndReport()
}//end class
