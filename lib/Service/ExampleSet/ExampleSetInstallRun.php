<?php

/**
 * OpenRegister Example Set Install Run
 *
 * The state of one install's write pass: the plan, the record as the install
 * grows it, the report, what the run sent, and the fragment being written
 * with the objects, files and context its placeholders draw on so far.
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
 * One install's write pass in progress; it never leaves ExampleSetInstaller.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
final class ExampleSetInstallRun {
	/**
	 * The report so far.
	 *
	 * @var array<string, mixed>
	 */
	public array $report = [];

	/**
	 * What this run sent, by record key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $written = [];

	/**
	 * The context every placeholder of the set may draw on.
	 *
	 * @var array<string, mixed>
	 */
	public array $base = [];

	/**
	 * The fragment being written.
	 *
	 * @var array<string, mixed>
	 */
	public array $fragment = [];

	/**
	 * The fragment's objects so far, by key: as stored, or what stands in for them.
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
	 * The context the fragment's changes, app config and preferences draw on now.
	 *
	 * @var array<string, mixed>
	 */
	public array $context = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record; updated and kept after every write.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $plan,
		public array $record,
	) {
	}//end __construct()

	/**
	 * The set id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function set(): string {
		return (string) $this->plan['set'];
	}//end set()

	/**
	 * Whether this run rewrites what the set wrote before (`--refresh`).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function refresh(): bool {
		return ($this->plan['refresh'] ?? false) === true;
	}//end refresh()

	/**
	 * The context an object's placeholders draw on: the objects and files so far, then the base.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function objectContext(): array {
		return (['objects' => $this->objects, 'files' => $this->files] + $this->base);
	}//end objectContext()
}//end class
