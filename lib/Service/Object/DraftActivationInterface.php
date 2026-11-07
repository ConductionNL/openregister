<?php

/**
 * DraftActivationInterface: what callers outside the Service component use of DraftActivation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use OCP\IUser;

/**
 * The methods of DraftActivation that callers outside the Service component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to DraftActivation.
 */
interface DraftActivationInterface {

	/**
	 * Make a signed-in user's own draft definitive.
	 *
	 * The owner is built here, from the user the caller's session holds, so
	 * no controller ever writes an owner assertion of its own.
	 *
	 * @param Register             $register The draft's register.
	 * @param Schema               $schema   The draft's schema.
	 * @param string               $draftId  The draft's uuid.
	 * @param IUser                $user     The acting user.
	 * @param array<string, mixed> $changes  Last property values, laid over the stored draft before it is judged.
	 *
	 * @return ObjectEntity The object, now `active`.
	 *
	 * @throws DraftActivationRefusedException 404 unknown, 409 not a draft, 403 not the owner's, 422 not valid in full.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function activateAsUser(Register $register, Schema $schema, string $draftId, IUser $user, array $changes = []): ObjectEntity;
}//end interface
