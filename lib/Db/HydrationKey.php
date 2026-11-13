<?php

/**
 * OpenRegister HydrationKey
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
 * Which keys of a body an entity's setter-based hydrate may act on.
 *
 * The setter is found as 'set' . ucfirst($key), so `Organisation` reaches
 * setOrganisation() just like `organisation`. A caller that strips the exact
 * key before hydrating is then bypassed by the capitalised spelling. Field
 * names start lower-case, so a key that does not is not a field name.
 */
final class HydrationKey {
	/**
	 * Whether a key names a field the way the entity spells it.
	 *
	 * @param int|string $key The body key.
	 *
	 * @return bool True for a string key whose first character is not upper-case.
	 *
	 * @spec exclude entity plumbing, not a capability of its own
	 */
	public static function isFieldName(int|string $key): bool {
		return is_string($key) === true && $key !== '' && lcfirst($key) === $key;
	}//end isFieldName()
}//end class
