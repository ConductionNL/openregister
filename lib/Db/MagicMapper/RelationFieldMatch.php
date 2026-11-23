<?php

/**
 * RelationFieldMatch
 *
 * A dotted relation-field filter: the relation field and the referenced id.
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
 * One `_relations.<field>` filter: the relation field named and the id of the
 * object it must reference (ADR-011 Rule 2, value object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class RelationFieldMatch
{

	/**
	 * Constructor.
	 *
	 * @param string $field The relation field (the part after `_relations.`).
	 * @param string $value The referenced object id the field must carry.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $value,
	) {
	}//end __construct()
}//end class
