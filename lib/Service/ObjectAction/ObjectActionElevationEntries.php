<?php

/**
 * An action's `elevate` entries resolved to the ids a write is checked against.
 *
 * A declaration names registers and schemas by slug; a permission check sees
 * ids. Resolving once, before the run, keeps the check itself a comparison.
 * An entry whose register or schema does not resolve is dropped, never
 * widened: an elevation that cannot say exactly where it applies applies
 * nowhere.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Resolves `elevate` entries to register and schema ids.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
class ObjectActionElevationEntries {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registers Resolves a register by id or slug.
	 * @param SchemaMapper   $schemas   Resolves a schema by id or slug.
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
	) {
	}//end __construct()

	/**
	 * The entries with their register and schema as ids.
	 *
	 * @param list<array{register: string, schema: string, verbs: list<string>}> $entries The declared entries.
	 *
	 * @return list<array{register: string, schema: string, verbs: list<string>}> The resolved entries.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public function resolve(array $entries): array {
		$resolved = [];
		foreach ($entries as $entry) {
			try {
				$register = $this->registers->find($entry['register'], _rbac: false, _multitenancy: false);
				$schema = $this->schemas->find($entry['schema'], _rbac: false, _multitenancy: false);
			} catch (Throwable) {
				continue;
			}

			$resolved[] = ['register' => (string)$register->getId(), 'schema' => (string)$schema->getId(), 'verbs' => $entry['verbs']];
		}

		return $resolved;
	}//end resolve()
}//end class
