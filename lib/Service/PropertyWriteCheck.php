<?php

/**
 * PropertyWriteCheck: the properties an incoming write touches, checked against their rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;

/**
 * One write to check: the schema, the stored object with the create flag,
 * and the incoming data.
 *
 * ADR-011 Rule 2 operation object for PropertyRbacHandler::unauthorizedProperties().
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class PropertyWriteCheck {

	/**
	 * Describe it.
	 *
	 * @param Schema                $schema   The schema the object belongs to.
	 * @param PropertyAccessRequest $request  The stored object (empty for a create) and whether this is a create.
	 * @param array                 $incoming The incoming data.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly PropertyAccessRequest $request,
		public readonly array $incoming,
	) {
	}//end __construct()
}//end class
