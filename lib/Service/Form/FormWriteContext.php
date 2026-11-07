<?php

/**
 * OpenRegister Form Write Context
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;

/**
 * Under whose authority the writes of one submit are made.
 *
 * A scope-value owner (decision 195) has no Nextcloud user for RBAC to
 * judge, so the owner assertion is the authorisation on every write of its
 * draft. Starting it does not ask the destination's public create rule: the
 * in-process caller vouched for the subject, exactly as it does when that
 * subject's app creates the object outright. Updating or sending it was
 * authorised by DraftFinder::own() before the write. Only a draft save and
 * the one promotion of an owned draft hand an owner in, so a scoped write is
 * always a draft or that promotion; a fresh submit never carries an owner
 * and stays under RBAC.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormWriteContext {

	/**
	 * Bundle the authority.
	 *
	 * @param IUser|null      $subject The subject the save runs as.
	 * @param string|null     $status  The lifecycle status to store (`draft`, `active`), or none.
	 * @param string|null     $uuid    The object to update (a draft), for a single write.
	 * @param DraftOwner|null $owner   A draft's owner; a scope value is stamped on create, and
	 *                                 authorises every write of its own draft.
	 */
	public function __construct(
		public ?IUser $subject,
		public ?string $status = null,
		public ?string $uuid = null,
		public ?DraftOwner $owner = null,
	) {
	}//end __construct()

	/**
	 * Whether the owner is a scope value, which stands in for RBAC.
	 *
	 * @return bool True for a scope-value owner.
	 */
	public function isScoped(): bool {
		return $this->owner !== null && $this->owner->isScoped() === true;
	}//end isScoped()

	/**
	 * Whether the save asks RBAC.
	 *
	 * @return bool False only for a scope-value owner.
	 */
	public function rbac(): bool {
		return $this->isScoped() === false;
	}//end rbac()

	/**
	 * Whether the object is stored without an owner: an anonymous, unscoped write.
	 *
	 * @return bool True when nobody owns it.
	 */
	public function unowned(): bool {
		return $this->subject === null && $this->isScoped() === false;
	}//end unowned()

	/**
	 * The owner stamped on a new object: the scope value of a draft being started, else none.
	 *
	 * @return string|null The owner value.
	 */
	public function explicitOwner(): ?string {
		if ($this->isScoped() === true && $this->uuid === null) {
			return $this->owner->value();
		}

		return null;
	}//end explicitOwner()
}//end class
