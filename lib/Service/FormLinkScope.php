<?php

/**
 * OpenRegister Form Link Scope
 *
 * The object, register and optional schema a form link belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec exclude ADR-019 Tier-2 integration link-service value; the form-link contract is owned by the integration-forms capability.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\FormLink;

/**
 * Where a form link points: an object in a register, with a schema or none.
 *
 * Unlike LinkTarget the schema stays null when none is given, which is
 * what a form link row stores.
 *
 * @spec exclude ADR-019 Tier-2 integration link-service value; the form-link contract is owned by the integration-forms capability.
 */
final class FormLinkScope {

	/**
	 * The OR schema id, or null.
	 *
	 * @var integer|null
	 */
	private ?int $schemaId = null;

	/**
	 * Constructor.
	 *
	 * @param string  $objectUuid The OR object UUID.
	 * @param integer $registerId The OR register id.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service value; the form-link contract is owned by the integration-forms capability.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
	) {
	}//end __construct()

	/**
	 * A copy in the given schema.
	 *
	 * @param integer|null $schemaId The OR schema id, or null for none.
	 *
	 * @return self The copy.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service value; the form-link contract is owned by the integration-forms capability.
	 */
	public function inSchema(?int $schemaId): self {
		$copy           = clone $this;
		$copy->schemaId = $schemaId;
		return $copy;
	}//end inSchema()

	/**
	 * A new, unsaved link row in this scope, linked by the given user.
	 *
	 * @param string $uid The linking user.
	 *
	 * @return FormLink The row.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service value; the form-link contract is owned by the integration-forms capability.
	 */
	public function newLink(string $uid): FormLink {
		$link = new FormLink();
		$link->setObjectUuid($this->objectUuid);
		$link->setRegisterId($this->registerId);
		$link->setSchemaId($this->schemaId);
		$link->setLinkedBy($uid);
		return $link;
	}//end newLink()
}//end class
