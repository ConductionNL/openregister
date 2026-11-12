<?php

/**
 * OpenRegister Example Set Check Run
 *
 * The state of one check of an example set: the plan, the record, the refusals
 * and counts so far, the objects declared so far, and the fragment being checked
 * with the objects and files its placeholders draw on.
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
 * One check in progress; it never leaves ExampleSetInstaller.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
final class ExampleSetCheckRun {
	/**
	 * Every refusal so far.
	 *
	 * @var array<int, string>
	 */
	public array $refusals = [];

	/**
	 * Objects checked, per register and schema.
	 *
	 * @var array<string, int>
	 */
	public array $checked = [];

	/**
	 * Changes checked, per register and schema.
	 *
	 * @var array<string, int>
	 */
	public array $checkedChanges = [];

	/**
	 * Objects declared earlier in the set, for lookups: register, schema and the row that stands in.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $planned = [];

	/**
	 * Account ids the set creates, valid for a `user` format.
	 *
	 * @var array<int, string>
	 */
	public array $users = [];

	/**
	 * The context every placeholder of the set may draw on.
	 *
	 * @var array<string, mixed>
	 */
	public array $base = [];

	/**
	 * The fragment being checked.
	 *
	 * @var array<string, mixed>
	 */
	public array $fragment = [];

	/**
	 * The fragment's objects so far, by key.
	 *
	 * @var array<string, mixed>
	 */
	public array $objects = [];

	/**
	 * The fragment's file ids so far, by object key.
	 *
	 * @var array<string, array<int, int>>
	 */
	public array $files = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record as an earlier install left it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $plan,
		public readonly array $record,
	) {
	}//end __construct()

	/**
	 * The context an object's placeholders draw on: the objects and files so far, then the base.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function objectContext(): array {
		return (['objects' => $this->objects, 'files' => $this->files] + $this->base);
	}//end objectContext()
}//end class
