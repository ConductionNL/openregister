<?php

/**
 * OpenRegister Example Set Recorded Object
 *
 * One object an install recorded: its record key and its entry (register, schema, id).
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
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * An object the record holds, by its record key.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
 */
final class ExampleSetRecordedObject {
	/**
	 * Constructor.
	 *
	 * @param string               $name  The object's record key.
	 * @param array<string, mixed> $entry The record entry: register, schema, id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $entry,
	) {
	}//end __construct()
}//end class
