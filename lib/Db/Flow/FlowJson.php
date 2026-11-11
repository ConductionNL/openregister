<?php

/**
 * OpenRegister FlowJson
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Flow
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

namespace OCA\OpenRegister\Db\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\TypedFieldsJson;

/**
 * The JSON form of a flow: its typed fields, with the uuid as id and the
 * defaults a flow reads as when a field is unset.
 *
 * `semver` stays null until the flow has been published: a draft has not
 * been compared with anything yet, and a number would be a claim. A null
 * `lastRunAt` stays null rather than becoming an empty string: the API
 * distinguishes "never run" from "ran and said nothing".
 *
 * @spec exclude flow plumbing moved out of the entity, not a capability of its own
 */
final class FlowJson {

	/**
	 * The serialised flow.
	 *
	 * @param array<string, string> $fieldTypes The flow's field types.
	 * @param array<string, mixed>  $values     The flow's field values by name.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude flow plumbing moved out of the entity, not a capability of its own
	 */
	public function serialise(array $fieldTypes, array $values): array {
		$json = (new TypedFieldsJson())->serialise(fieldTypes: $fieldTypes, values: $values);

		return array_merge($json, $this->defaults(values: $values));
	}//end serialise()

	/**
	 * The id and the values that read as a default when unset.
	 *
	 * @param array<string, mixed> $values The flow's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(array $values): array {
		return [
			'id' => $values['uuid'],
			'enabled' => (bool)$values['enabled'],
			'version' => (int)($values['version'] ?? 1),
			'lifecycleStatus' => ($values['lifecycleStatus'] ?? FlowVersion::STATUS_DRAFT),
			'executionMode' => ($values['executionMode'] ?? Flow::MODE_ASYNC),
			'nodes' => ($values['nodes'] ?? []),
			'edges' => ($values['edges'] ?? []),
			'annotations' => ($values['annotations'] ?? []),
			'limits' => ($values['limits'] ?? []),
		];
	}//end defaults()
}//end class
