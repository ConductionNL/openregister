<?php

/**
 * Mapper for xWiki link entities.
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
 * Class XwikiLinkMapper
 *
 * @template-extends QBMapper<XwikiLink>
 * @method list<XwikiLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class XwikiLinkMapper extends QBMapper implements XwikiLinkMapperInterface {

	use ObjectKeyLinkTrait;
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_xwiki_links', entityClass: XwikiLink::class);
	}//end __construct()

	/**
	 * Find a specific xWiki link by object UUID and page reference.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $pageReference The canonical xWiki page reference.
	 *
	 * @return XwikiLink|null The link or null if not found.
	 */
	public function findByObjectAndPage(string $objectUuid, string $pageReference): ?XwikiLink {
		$link = $this->findObjectLink(objectUuid: $objectUuid, key: new LinkColumn(name: 'page_reference', value: $pageReference));
		if ($link instanceof XwikiLink) {
			return $link;
		}

		return null;
	}//end findByObjectAndPage()

	/**
	 * Delete an xWiki link by object UUID + page reference (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $pageReference The canonical xWiki page reference.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndPage(string $objectUuid, string $pageReference): int {
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'page_reference', value: $pageReference));
	}//end deleteByObjectAndPage()
}//end class
