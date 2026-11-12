<?php

/**
 * ExampleSetRemoverInterface: what callers outside the component use of ExampleSetRemover.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Remove an installed example set.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ExampleSetRemoverInterface {

	/**
	 * Remove what the record holds.
	 *
	 * @param string $set The set id.
	 *
	 * @return array{installed: bool, removed: array<string, int>, left: array<int, string>, failed: array<int, string>}
	 *         Whether there was anything to remove, counts per kind, adopted accounts left in place, and what could
	 *         not be removed (still in the record).
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function remove(string $set): array;
}//end interface
