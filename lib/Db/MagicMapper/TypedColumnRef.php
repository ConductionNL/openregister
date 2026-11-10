<?php

/**
 * TypedColumnRef
 *
 * A filtered column reference with its declared type and platform.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * A column reference a filter compares, with the type the schema declares for
 * its property and whether the platform is PostgreSQL: what decides whether
 * the reference must be text-cast for a non-numeric value (ADR-011 Rule 2,
 * value object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class TypedColumnRef
{

	/**
	 * Constructor.
	 *
	 * @param string $ref          The column reference as written in the SQL.
	 * @param string $propertyType The declared schema type of the property.
	 * @param bool   $isPostgres   Whether the platform is PostgreSQL.
	 */
	public function __construct(
		public readonly string $ref,
		public readonly string $propertyType,
		public readonly bool $isPostgres,
	) {
	}//end __construct()
}//end class
