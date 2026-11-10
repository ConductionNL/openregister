<?php

/**
 * Table Object Ref
 *
 * An object identifier (id, uuid or slug) within one register+schema table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterSchema;

/**
 * The identifier of an object and the resolved register and schema whose table holds it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TableObjectRef {
	/**
	 * Constructor.
	 *
	 * @param string         $id    Object id, uuid or slug.
	 * @param RegisterSchema $table The register and schema.
	 */
	public function __construct(
		public readonly string $id,
		public readonly RegisterSchema $table,
	) {
	}//end __construct()
}//end class
