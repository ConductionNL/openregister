<?php

/**
 * OpenRegister Example Set Resolved Object
 *
 * A declared object with what its resolution gave: the filled data, what to
 * send, and the declared owner.
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
 * A declared object and its resolution.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
final class ExampleSetResolvedObject {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object   The declared object.
	 * @param array<string, mixed> $resolved `data`, `sent`, `refusal` and `owner`, as the installer resolves them.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $object,
		public readonly array $resolved,
	) {
	}//end __construct()
}//end class
