<?php

/**
 * MagicColumnNameClaims: the physical column names one table plan has taken.
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
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Psr\Log\LoggerInterface;

/**
 * Give each property of one table plan a column name of its own.
 *
 * Sanitising is not injective (`firstName` and `first_name` both become
 * `first_name`), so a later claimant of a taken name gets a numeric suffix
 * rather than silently sharing the column.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicColumnNameClaims {
	/**
	 * The names taken so far, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $taken;

	/**
	 * Start from the columns already in the plan.
	 *
	 * @param array           $columns Column definitions already planned.
	 * @param LoggerInterface $logger  Receives a warning per collision.
	 */
	public function __construct(array $columns, private readonly LoggerInterface $logger) {
		$this->taken = array_fill_keys(array_column($columns, 'name'), true);
	}//end __construct()

	/**
	 * Claim a column name for a property.
	 *
	 * @param string $propertyName The property the column stores.
	 * @param string $column       The name the property sanitises to.
	 *
	 * @return string The name claimed: $column, or $column with a suffix.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function claim(string $propertyName, string $column): string {
		$claimed = $this->firstFree(base: $column);
		if ($claimed !== $column) {
			$this->logger->warning(
				message: '[MagicMapper] Column name collision after sanitisation; disambiguating',
				context: ['propertyName' => $propertyName, 'collidingColumn' => $column, 'resolvedColumn' => $claimed]
			);
		}

		$this->taken[$claimed] = true;

		return $claimed;
	}//end claim()

	/**
	 * The base name when free, else the base with the lowest free suffix.
	 *
	 * @param string $base The wanted name.
	 *
	 * @return string The free name.
	 */
	private function firstFree(string $base): string {
		$candidate = $base;
		$suffix = 0;
		while (isset($this->taken[$candidate]) === true) {
			$suffix++;
			$candidate = $base . '_' . $suffix;
		}

		return $candidate;
	}//end firstFree()
}//end class
