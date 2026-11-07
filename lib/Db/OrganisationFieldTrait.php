<?php

/**
 * OpenRegister OrganisationFieldTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Explicit accessors for an entity's owning organisation UUID.
 *
 * Unlike the magic setter, setOrganisation() marks the field updated even when
 * the value does not change.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
trait OrganisationFieldTrait {

	/**
	 * Get the organisation UUID
	 *
	 * @return string|null The organisation UUID
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function getOrganisation(): ?string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * Set the organisation UUID
	 *
	 * @param string|null $organisation The organisation UUID
	 *
	 * @return void
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function setOrganisation(?string $organisation): void {
		$this->organisation = $organisation;
		$this->markFieldUpdated(attribute: 'organisation');
	}//end setOrganisation()
}//end trait
