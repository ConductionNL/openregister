<?php

/**
 * OpenRegister Object Check
 *
 * One object an example set asks its schema's validator about, before it writes.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The object to validate, and the account ids the set creates before it writes objects.
 *
 * An id in $plannedUsers passes a property of format `user` although the account does not exist yet.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
final class ObjectCheck {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object       The object.
	 * @param array<int, string>   $plannedUsers Account ids the set creates before it writes objects.
	 */
	public function __construct(
		public readonly array $object,
		public readonly array $plannedUsers=[],
	) {
	}//end __construct()
}//end class
