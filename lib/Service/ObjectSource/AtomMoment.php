<?php

/**
 * OpenRegister Atom Moment
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * A moment an app entity hands out, as an ATOM string; shared by the Deck and Talk object sources.
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */
final class AtomMoment {

	/**
	 * The value as an ATOM string when it is a moment.
	 *
	 * @param mixed $value What the entity's getter returned.
	 *
	 * @return string|null Null when the value is not a moment.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public static function of(mixed $value): ?string {
		if ($value instanceof \DateTimeInterface) {
			return $value->format(\DateTime::ATOM);
		}

		return null;
	}//end of()
}//end class
