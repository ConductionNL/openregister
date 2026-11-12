<?php

/**
 * ExampleSetCatalogueInterface: what callers outside the Service component use of ExampleSetCatalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * Lists the example sets the enabled apps ship and gathers the plan of one, as the example-set commands do.
 *
 * @spec openspec/specs/example-sets/spec.md
 */
interface ExampleSetCatalogueInterface {

	/**
	 * Every set any enabled app ships a fragment of, with those apps.
	 *
	 * @return array<string, array<int, string>> App ids by set id, both sorted: the apps come from enabledApps(),
	 *                                           which sorts them.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-can-list-the-sets-req-exs-008
	 */
	public function sets(): array;

	/**
	 * The plan of one set: its cast and its fragments in order.
	 *
	 * @param string $set The set id.
	 *
	 * @return array<string, mixed> `set`, `title`, `timezone`, `cast` (people, organisations and groups by key,
	 *                              plus `entries`, all three merged), `requires` (OpenRegister register slug to `{mock}`),
	 *                              `fragments` (each with `app`, `file`, `objects`, `changes` and `groups`) and
	 *                              `skipped` (named apps that are not enabled).
	 *
	 * @throws ExampleSetException When the set cannot be gathered; the message names why and where.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function gather(string $set): array;
}//end interface
