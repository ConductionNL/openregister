<?php

/**
 * OpenRegister DeleteCaller
 *
 * Who asked for a delete, and which access filters the delete runs under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The acting user and the RBAC and multitenancy flags of one delete.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class DeleteCaller {
	/**
	 * Constructor.
	 *
	 * @param string|null $userId       The acting user; null resolves the session user.
	 * @param bool        $rbac         Whether RBAC applies.
	 * @param bool        $multitenancy Whether the organisation filter applies.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flags mirror DeleteObject::deleteObject().
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public ?string $userId = null,
		public bool $rbac = true,
		public bool $multitenancy = true,
	) {
	}//end __construct()
}//end class
