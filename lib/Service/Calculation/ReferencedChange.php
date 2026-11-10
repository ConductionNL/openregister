<?php

/**
 * OpenRegister ReferencedChange
 *
 * A save of a referenced object, as the referenced-calculation refresher sees it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * The updated object's uuid and the top-level fields of it that changed and
 * that some materialised calculation reads.
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */
final class ReferencedChange {

	/**
	 * Constructor.
	 *
	 * @param string             $uuid    The updated object's uuid.
	 * @param array<int, string> $changed The fields that changed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly array $changed,
	) {
	}//end __construct()
}//end class
