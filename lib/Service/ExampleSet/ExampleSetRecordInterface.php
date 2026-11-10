<?php

/**
 * ExampleSetRecordInterface: what callers outside the component use of ExampleSetRecord.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Read what an example set left behind on this instance.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ExampleSetRecordInterface {

	/**
	 * What an earlier install made.
	 *
	 * @param string $set The set id.
	 *
	 * @return array{users: array<string, string>, groups: array<int, string>, memberships: array<int, array{0: string, 1: string}>,
	 *               objects: array<string, array{register: string, schema: string, id: string}>,
	 *               changes: array<string, array<string, mixed>>, registers: array<string, array<int, array{appId: string, jobId: string}>>}
	 *               `users` maps a uid to `created` or `adopted`; `objects` and `changes` are keyed `<app>/<key>` in
	 *               write order; `registers` maps a register slug this set imported to the import jobs it created;
	 *               `files` maps `<app>/<key>` to the files attached to that object; `appConfig` maps `<app>/<key>` to
	 *               what was written and what it replaced (never a sensitive value); `userPreferences` maps
	 *               `<app>/<uid>/<key>` to what was written for that account and what it replaced; `organisations` lists the uuids
	 *               of the OpenRegister organisations the set created; `tasks` and `views` map `<app>/<key>` to the
	 *               uuid of the engine task or saved view the set wrote.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function read(string $set): array;

	/**
	 * Whether a record names nothing.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function isEmpty(array $record): bool;
}//end interface
