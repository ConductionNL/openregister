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

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class FormLinkMapper
 *
 * @template-extends QBMapper<FormLink>
 * @method list<FormLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class FormLinkMapper extends QBMapper implements FormLinkMapperInterface {

	use ObjectKeyLinkTrait;
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
		$key = (new LinkColumn(name: 'form_id', value: $formId))
			->also(column: new LinkColumn(name: 'submission_id', value: null));

		return $this->asFormLink(link: $this->findObjectLink(objectUuid: $objectUuid, key: $key));
	}//end findFormLink()

	/**
	 * Find a submission-level link for a (object, form, submission) tuple.
	 *
	 * @param string            $objectUuid The OR object UUID.
	 * @param FormSubmissionRef $submission The form and submission ids.
	 *
	 * @return FormLink|null
	 */
	public function findSubmissionLink(string $objectUuid, FormSubmissionRef $submission): ?FormLink {
		$key = (new LinkColumn(name: 'form_id', value: $submission->formId))
			->also(column: new LinkColumn(name: 'submission_id', value: $submission->submissionId));

		return $this->asFormLink(link: $this->findObjectLink(objectUuid: $objectUuid, key: $key));
	}//end findSubmissionLink()

	/**
	 * A found link as a form link, or null.
	 *
	 * @param Entity|null $link The found link.
	 *
	 * @return FormLink|null The form link.
	 */
	private function asFormLink(?Entity $link): ?FormLink {
		if ($link instanceof FormLink) {
			return $link;
		}

		return null;
	}//end asFormLink()

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
		return $this->deleteObjectLinks(objectUuid: $objectUuid, key: new LinkColumn(name: 'form_id', value: $formId));
	}//end deleteByObjectAndForm()
}//end class
