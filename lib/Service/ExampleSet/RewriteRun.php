<?php

/**
 * OpenRegister Rewrite Run
 *
 * One rewrite of a kept object on refresh, as the example-set rewriter runs it.
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
 * The run of one rewrite (store, fragment, declared object, stored row, where and write rules), the declaration
 * resolved today, and the tally the rewrite reports into.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */
final class RewriteRun {

	/**
	 * The `store`, the `fragment`, the declared `object`, the stored `row`, the object's `where` and the schema's `rules`.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $run;

	/**
	 * The declaration resolved today: `refusal`, `data`, `sent`, and maybe `owner`.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $resolved;

	/**
	 * The report and what this run sent, by record key.
	 *
	 * @var RewriteTally
	 */
	public readonly RewriteTally $tally;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $run      The `store`, the `fragment`, the declared `object`, the stored `row`, the
	 *                                       object's `where` and the schema's `rules`.
	 * @param array<string, mixed> $resolved The declaration resolved today: `refusal`, `data`, `sent`, and maybe `owner`.
	 * @param RewriteTally         $tally    The report and what this run sent, by record key.
	 */
	public function __construct(array $run, array $resolved, RewriteTally $tally) {
		$this->run      = $run;
		$this->resolved = $resolved;
		$this->tally    = $tally;
	}//end __construct()
}//end class
