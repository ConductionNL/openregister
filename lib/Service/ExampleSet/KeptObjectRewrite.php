<?php

/**
 * OpenRegister Kept Object Rewrite
 *
 * One kept object to rewrite on `--refresh`, and its declaration resolved today.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The `store`, the `fragment`, the declared `object` and the stored `row` of one kept object, and its declaration
 * resolved today: `refusal`, `data`, `sent`, and maybe `owner`.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */
final class KeptObjectRewrite {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $kept     The `store`, the `fragment`, the declared `object` and the stored `row`.
	 * @param array<string, mixed> $resolved The declaration resolved today.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	public function __construct(
		public readonly array $kept,
		public readonly array $resolved,
	) {
	}//end __construct()
}//end class
