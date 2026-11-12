<?php

/**
 * OpenRegister Example Set Key Of
 *
 * The `key` of a declared example-set entry, shared by the catalogue and the
 * shapes it hands a cast and a fragment to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Reads the `key` of a declared entry.
 */
trait ExampleSetKeyOf {
	/**
	 * The `key` of a declared entry, or '' when it has none.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	protected function keyOf(mixed $entry): string {
		if (is_array($entry) === false || is_scalar($entry['key'] ?? null) === false) {
			return '';
		}

		return (string) $entry['key'];
	}//end keyOf()
}//end trait
