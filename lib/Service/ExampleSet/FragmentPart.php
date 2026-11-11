<?php

/**
 * OpenRegister Fragment Part
 *
 * One part of a fragment's declarations, as the install record keys it.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * A fragment, as gathered for this run, and the record part whose declarations it lists.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
 */
final class FragmentPart {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param ExampleSetRecordPart $part     The record part, which is also the fragment's list of declarations, and its noun.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public function __construct(
		public readonly array $fragment,
		public readonly ExampleSetRecordPart $part,
	) {
	}//end __construct()
}//end class
