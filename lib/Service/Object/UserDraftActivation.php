<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * A signed-in user's request to make a draft definitive: which draft, who asks, and the last changes.
 *
 * The user is carried, not an owner: DraftActivation builds the owner from it, so no
 * controller ever writes an owner assertion of its own.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class UserDraftActivation {

	/**
	 * Constructor.
	 *
	 * @param DraftAddress         $address The draft's register, schema and uuid.
	 * @param IUser                $user    The acting user.
	 * @param array<string, mixed> $changes Last property values, laid over the stored draft before it is judged.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function __construct(
		public readonly DraftAddress $address,
		public readonly IUser $user,
		public readonly array $changes = [],
	) {
	}//end __construct()
}//end class
