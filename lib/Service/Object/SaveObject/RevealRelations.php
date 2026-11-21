<?php

/**
 * OpenRegister RevealRelations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;

/**
 * Which values of a stored relations index belong to explicit-reveal properties.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
final class RevealRelations {

	/**
	 * The schema's explicit-reveal property names.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return list<string> The names.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public static function explicitProperties(?Schema $schema): array {
		return ExplicitReveal::explicitProperties(schema: $schema);
	}//end explicitProperties()

	/**
	 * A relations index without the explicit-reveal values, or null when it holds none.
	 *
	 * @param array|null   $relations  The stored index, by path or as a list.
	 * @param list<string> $properties The explicit-reveal property names.
	 * @param list<string> $values     The string values the row holds in those properties.
	 *
	 * @return array|null The index to store, or null when nothing changes.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public static function withoutRevealValues(?array $relations, array $properties, array $values): ?array {
		if ($relations === null || $relations === []) {
			return null;
		}

		if (array_is_list($relations) === true) {
			$kept = array_values(array_filter($relations, static fn (mixed $value): bool => in_array($value, $values, true) === false));
		} else {
			$kept = array_filter(
				$relations,
				static fn (int|string $path): bool => in_array(explode('.', (string)$path, 2)[0], $properties, true) === false,
				ARRAY_FILTER_USE_KEY
			);
		}

		if (count($kept) === count($relations)) {
			return null;
		}

		return $kept;
	}//end withoutRevealValues()

}//end class
