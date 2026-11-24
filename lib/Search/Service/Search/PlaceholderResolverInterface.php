<?php

/**
 * PlaceholderResolverInterface: what callers outside the Search component use of PlaceholderResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Search
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

namespace OCA\OpenRegister\Search\Service\Search;

use OCA\OpenRegister\Kernel\Contracts\Core\FilterPlaceholdersInterface;

/**
 * Resolves placeholder strings (`$now`, `$startOfMonth-1`, `$currentUser`, ...) to concrete values.
 *
 * Resolving a whole filter map is declared by the Core port it extends, so the
 * aggregation runner can ask for it without naming Search.
 */
interface PlaceholderResolverInterface extends FilterPlaceholdersInterface {

	/**
	 * Resolve a single value. Strings are parsed for placeholders; non-strings are passed through.
	 *
	 * @param mixed $value The value to resolve (typically a string placeholder).
	 *
	 * @return mixed DateTimeImmutable for date placeholders, string for $currentUser, the value otherwise.
	 */
	public function resolve(mixed $value): mixed;
}//end interface
