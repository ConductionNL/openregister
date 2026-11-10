<?php

/**
 * OpenRegister Example Set Record Part
 *
 * One part of an install record and its word for one entry in the report.
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
 * A record part (`changes`, `notes`, ...), which is also the fragment's list of declarations, and its noun.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
 */
final class ExampleSetRecordPart {
	/**
	 * Constructor.
	 *
	 * @param string $part The record part, which is also the fragment's list of declarations.
	 * @param string $noun The part's word for one entry in the report.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $part,
		public readonly string $noun,
	) {
	}//end __construct()
}//end class
