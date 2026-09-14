<?php

/**
 * Who holds which verb on a schema, per rule rather than per name.
 *
 * The audit answered per schema and per action before this: which groups hold
 * `read` on `zaak`. That is a set of names with no rule behind it, so the
 * reviewer who finds a group they did not expect has to go and discover WHERE it
 * was granted, at four levels, and that search is the expensive half of an
 * access review.
 *
 * The per-action index is kept beside the per-rule answer, so nothing that reads
 * the old shape has to change to keep working.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Assembles the per-rule scope audit.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class ScopeAudit {

	/**
	 * Constructor.
	 *
	 * @param ObjectPermissionsResolver $holders Reads an access set out of the rules.
	 */
	public function __construct(
		private readonly ObjectPermissionsResolver $holders = new ObjectPermissionsResolver(),
	) {
	}//end __construct()

	/**
	 * One row per schema of these registers.
	 *
	 * A schema that belongs to none of the registers in hand is skipped rather
	 * than reported against all of them: an audit that paired every schema with
	 * every register would report rules that do not apply, which is worse than
	 * reporting none.
	 *
	 * @param array<int, Register> $registers The registers to report on.
	 * @param array<int, Schema>   $schemas   The schemas to consider.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function rows(array $registers, array $schemas): array {
		$rows = [];

		foreach ($registers as $register) {
			$registerSchemaIds = ($register->getSchemas() ?? []);

			foreach ($schemas as $schema) {
				if (in_array($schema->getId(), $registerSchemaIds, false) === false) {
					continue;
				}

				$set = $this->holders->holders(
					blocks: [
						'schema' => $schema->getAuthorization(),
						'register' => $register->getAuthorization(),
					],
					roleDefinitions: $this->roleDefinitionsOf(register: $register)
				);

				$rows[] = [
					'register' => $register->getSlug(),
					'schema' => $schema->getSlug(),
					'holders' => $set['holders'],
					'denied' => $set['denied'],
					'byAction' => $this->byAction(holders: $set['holders']),
				];
			}
		}//end foreach

		return $rows;
	}//end rows()

	/**
	 * The same rules, indexed by verb.
	 *
	 * @param array<int, array<string, mixed>> $holders The principals and their rules.
	 *
	 * @return array<string, array<int, string>> Verb to the principals holding it.
	 */
	private function byAction(array $holders): array {
		$byAction = [];
		foreach ($holders as $holder) {
			foreach ($holder['verbs'] as $verb) {
				if (isset($byAction[$verb]) === false) {
					$byAction[$verb] = [];
				}

				if (in_array($holder['principal'], $byAction[$verb], true) === false) {
					$byAction[$verb][] = $holder['principal'];
				}
			}
		}

		return $byAction;
	}//end byAction()

	/**
	 * The role definitions a register declares.
	 *
	 * @param Register $register The register.
	 *
	 * @return mixed The definitions, or null when there are none.
	 */
	private function roleDefinitionsOf(Register $register): mixed {
		$configuration = $register->getConfiguration();
		if (is_array($configuration) === false) {
			return null;
		}

		return ($configuration['roles'] ?? null);
	}//end roleDefinitionsOf()
}//end class
