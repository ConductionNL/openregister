<?php

/**
 * MagicColumnNameTrait: the magic-table column name of a schema property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Name the column a property is stored in.
 *
 * Every class that writes, reads, searches, facets or aggregates a magic table
 * uses this one rule, so a property is always looked up in the column it was
 * created and written in.
 */
trait MagicColumnNameTrait {

	/**
	 * Convert a property name to its column name.
	 *
	 * A camelCase name becomes snake_case and is lower-cased; any
	 * character outside `[a-z0-9_]` becomes an underscore, runs of underscores
	 * collapse to one and trailing underscores are removed. A leading digit is
	 * kept and the name is not shortened, because existing tables were created
	 * with exactly these names (`inStock` is `in_stock`, `1abc` stays `1abc`).
	 *
	 * @param int|string $name The property name; PHP keeps a numeric array key as an int.
	 *
	 * @return string The column name.
	 */
	private function sanitizeColumnName(int|string $name): string {
		$name = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $name));
		$name = (string) preg_replace('/[^a-z0-9_]/', '_', $name);

		return rtrim((string) preg_replace('/_+/', '_', $name), '_');
	}//end sanitizeColumnName()
}//end trait
