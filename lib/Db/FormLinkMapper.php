<?php

/**
 * Mapper for form-link entities.
 *
 * Backs the Tier-2 forms integration leaf — the link rows in
 * `openregister_form_links` track which NC Forms forms (and their
 * individual submissions) are attached to which OR object.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class FormLinkMapper
 *
 * @template-extends QBMapper<FormLink>
 */
class FormLinkMapper extends QBMapper {
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
	use CountLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_form_links', entityClass: FormLink::class);

	}//end __construct()

	/**
	 * Find the form-level link (submissionId NULL) for the given
	 * `(object, form)` pair. Returns null when no such row exists.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return FormLink|null
	 */
	public function findFormLink(string $objectUuid, int $formId): ?FormLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('submission_id'));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findFormLink()

	/**
	 * Find a submission-level link for a (object, form, submission) tuple.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $formId The NC Forms form id.
	 * @param integer $submissionId The NC Forms submission id.
	 *
	 * @return FormLink|null
	 */
	public function findSubmissionLink(string $objectUuid, int $formId, int $submissionId): ?FormLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('submission_id', $qb->createNamedParameter($submissionId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findSubmissionLink()

	/**
	 * Delete every link row (form-level + submission-level) for a
	 * (object, form) pair — used when unlinking a form so all its
	 * submission-level rows are cleaned in one shot.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return integer Number of deleted rows.
	 */
	public function deleteByObjectAndForm(string $objectUuid, int $formId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByObjectAndForm()
}//end class
