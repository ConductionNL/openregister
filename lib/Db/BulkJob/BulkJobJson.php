<?php

/**
 * OpenRegister BulkJobJson
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\BulkJob
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

namespace OCA\OpenRegister\Db\BulkJob;

use OCA\OpenRegister\Db\TypedFieldsJson;

/**
 * The JSON form of a bulk job: its typed fields, the four outcome counters
 * grouped under `counts`, unset json fields as empty arrays, and whether the
 * job can still be reversed.
 *
 * @spec exclude bulk job plumbing moved out of the entity, not a capability of its own
 */
final class BulkJobJson {

	/**
	 * The outcome counters grouped under `counts`.
	 *
	 * @var string[]
	 */
	private const COUNTS = ['applied', 'skipped', 'refused', 'failed'];

	/**
	 * The serialised job.
	 *
	 * @param array<string, mixed> $values     The job's field values by name, with its field types under `fieldTypes`.
	 * @param bool                 $reversible Whether the job can be reversed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude bulk job plumbing moved out of the entity, not a capability of its own
	 */
	public function serialise(array $values, bool $reversible): array {
		$typed = new TypedFieldsJson();
		$names = array_diff(array_keys($values['fieldTypes']), self::COUNTS);
		$json = array_merge(
			$typed->pick(values: $values, names: $names),
			[
				'parameters' => ($values['parameters'] ?? []),
				'selection' => ($values['selection'] ?? []),
				'report' => ($values['report'] ?? []),
			]
		);
		$counts = $typed->pick(values: $values, names: self::COUNTS);

		return $typed->inserted(json: $json, entriesAfter: ['processed' => ['counts' => $counts], 'startedBy' => ['reversible' => $reversible]]);
	}//end serialise()
}//end class
