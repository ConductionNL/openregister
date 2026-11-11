<?php

/**
 * ObjectKeyLinkTrait: find or delete one object link by a second key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The link of an object that matches one more column, for link mappers keyed on object_uuid.
 */
trait ObjectKeyLinkTrait {
	use SelectByColumnTrait;

	/**
	 * The link of an object whose key column matches, or null when there is none.
	 *
	 * @param string     $objectUuid The object.
	 * @param LinkColumn $key        The further key columns.
	 *
	 * @return Entity|null The link.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	private function findObjectLink(string $objectUuid, LinkColumn $key): ?Entity {
		$qb = $this->selectWhere(column: new LinkColumn(name: 'object_uuid', value: $objectUuid));
		$key->applyTo(qb: $qb);

		return $this->findOneOrNull(query: $qb);
	}//end findObjectLink()

	/**
	 * Delete the links of an object whose key column matches.
	 *
	 * @param string     $objectUuid The object.
	 * @param LinkColumn $key        The second key.
	 *
	 * @return int The number of rows deleted.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	private function deleteObjectLinks(string $objectUuid, LinkColumn $key): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($key->equals(qb: $qb));

		return $qb->executeStatement();
	}//end deleteObjectLinks()
}//end trait
