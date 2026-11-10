<?php

/**
 * OpenRegister Example Set Row
 *
 * One filled row a fragment declares for an engine-row kind: the app that ships the fragment, the row's key in it and
 * its filled fields, which travel together into every kind's write and update.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The app, the key and the filled fields of one row an engine-row kind writes.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class ExampleSetRow {
	/**
	 * Constructor.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The row's key in the fragment.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $key,
		public readonly array $data,
	) {
	}//end __construct()
}//end class
