<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One person's link on an object, as a caller names it: the object, the
 * person (the vCard uid, or `user:<uid>`), and the role when they hold several.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
 */
final class PersonLinkRef {
	/**
	 * Name the link.
	 *
	 * @param string       $objectUuid The object uuid.
	 * @param string       $contactUid The person: the vCard uid, or `user:<uid>`.
	 * @param string|null  $role       The role, or null for any.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $contactUid,
		public readonly ?string $role,
	) {
	}//end __construct()
}//end class
