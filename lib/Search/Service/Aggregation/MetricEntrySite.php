<?php

/**
 * OpenRegister MetricEntrySite
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * Where a `metrics` entry sits: its aggregation, its index, and the properties
 * its schema declares. Builds the errors that name that place.
 */
final class MetricEntrySite {
	/**
	 * Constructor.
	 *
	 * @param string             $name     The aggregation name.
	 * @param int                $index    The entry's index in `metrics`.
	 * @param array<int, string> $propKeys Property names the schema declares.
	 */
	public function __construct(
		public readonly string $name,
		public readonly int $index,
		public readonly array $propKeys,
	) {
	}//end __construct()

	/**
	 * One validation error, its message prefixed with the entry's place.
	 *
	 * @param string $code   The error code.
	 * @param string $detail The message after `Aggregation "<name>" metrics[<index>]`.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public function error(string $code, string $detail): array {
		return [['code' => $code, 'message' => sprintf('Aggregation "%s" metrics[%d]', $this->name, $this->index) . $detail]];
	}//end error()

	/**
	 * Whether the schema declares a property.
	 *
	 * @param string $prop The property name.
	 *
	 * @return bool
	 */
	public function declares(string $prop): bool {
		return in_array($prop, $this->propKeys, true);
	}//end declares()
}//end class
