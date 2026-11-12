<?php

/**
 * OpenRegister Example Set Resolve Scope
 *
 * What an object's placeholders and lookups may draw on: the context, and the
 * objects a check declared earlier in the set.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The context and the planned objects one resolution draws on.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
final class ExampleSetResolveScope {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>             $context `cast`, `objects`, `today` and the lookups found so far.
	 * @param array<int, array<string, mixed>> $planned Objects a check declared earlier; none in a write.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $context,
		public readonly array $planned=[],
	) {
	}//end __construct()
}//end class
