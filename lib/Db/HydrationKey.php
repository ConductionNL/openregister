<?php

/**
 * OpenRegister HydrationKey
 *
 * Decides which keys of a hydrate array an entity sets.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use ReflectionMethod;

/**
 * Admits a hydrate key only in its exact spelling.
 *
 * PHP method names ignore case, so `'set' . ucfirst('Owner')` reaches the
 * same setter as `owner`. A caller that strips `owner` from user input would
 * then still let `Owner` through. A key is admitted when the entity has a
 * field of exactly that name, or declares a setter named exactly after it.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
final class HydrationKey {

	/**
	 * Whether hydrate() may set this key on the entity.
	 *
	 * @param object     $entity The entity being hydrated.
	 * @param int|string $key    The key from the hydrate array.
	 *
	 * @return bool True when the key is spelled as a field or an explicit setter.
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public static function admits(object $entity, int|string $key): bool {
		if (is_string($key) === false || $key === '') {
			return false;
		}

		if (property_exists($entity, $key) === true) {
			return true;
		}

		$method = 'set' . ucfirst($key);
		if (lcfirst($key) !== $key || method_exists($entity, $method) === false) {
			return false;
		}

		return (new ReflectionMethod($entity, $method))->getName() === $method;
	}//end admits()
}//end class
