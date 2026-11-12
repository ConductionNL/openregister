<?php

/**
 * What an anonymous caller does not get to read in a record's `@self` envelope.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use JsonSerializable;

/**
 * Removes the people from `@self`, wherever an output carries one.
 *
 * It works on output only: rules read a record through
 * ObjectEntity::getMatchEnvelope(), which this class does not touch.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class AnonymousEnvelope implements AnonymousEnvelopeInterface {

	/**
	 * The `@self` fields withheld whole: they name users (`retention` in its holds and outcomes) or link to their mail, contacts and other items.
	 *
	 * @var list<string>
	 */
	private const WITHHELD = ['owner', 'authorization', 'retention', 'mail', 'contacts', 'notes', 'todos', 'calendar', 'talk', 'deck'];

	/**
	 * The `@self` fields that stay readable without the key that names a user.
	 *
	 * @var array<string, list<string>> `@self` field => keys inside it
	 */
	private const WITHHELD_WITHIN = [
		'locked'   => ['user'],
		'deleted'  => ['deletedBy'],
		'archived' => ['by'],
		'frozen'   => ['by'],
		'register' => ['owner', 'authorization'],
		'schema'   => ['owner', 'authorization'],
	];

	/**
	 * The fields withheld from a register or schema row: they name users.
	 *
	 * @var list<string>
	 */
	private const WITHHELD_FROM_CONFIGURATION = ['owner', 'authorization'];

	/**
	 * The keys of a register or schema row that hold nested register or schema rows.
	 *
	 * @var list<string>
	 */
	private const NESTED_CONFIGURATION = ['register', 'registers', 'schema', 'schemas'];

	/**
	 * The output as an anonymous caller may read it, at any depth.
	 *
	 * @param mixed $data The output.
	 *
	 * @return mixed The output without the withheld `@self` fields.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function redact(mixed $data): mixed {
		if ($data instanceof JsonSerializable === true) {
			$data = $data->jsonSerialize();
		}

		if (is_array($data) === false) {
			return $data;
		}

		$data = array_map([$this, 'redact'], $data);
		if (is_array($data['@self'] ?? null) === true) {
			$data['@self'] = $this->withoutPeople(self: $data['@self']);
		}

		return $data;
	}//end redact()

	/**
	 * A register or schema answer as an anonymous caller may read it.
	 *
	 * The answer is one row or a `results` list of rows. Each row loses its
	 * owner and authorization, and so do the register and schema rows nested
	 * in it. Only those keys are walked, so a schema property that happens to
	 * be called `owner` keeps its definition.
	 *
	 * @param mixed $data The answer.
	 *
	 * @return mixed The answer without the withheld fields.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function redactConfiguration(mixed $data): mixed {
		$data = $this->redact(data: $data);
		if (is_array($data) === false) {
			return $data;
		}

		if (is_array($data['results'] ?? null) === true) {
			$data['results'] = array_map([$this, 'configurationRow'], $data['results']);
			return $data;
		}

		return $this->configurationRow(row: $data);
	}//end redactConfiguration()

	/**
	 * One register or schema row, or a list of them, without the withheld fields.
	 *
	 * @param mixed $row The row.
	 *
	 * @return mixed The row without them.
	 */
	private function configurationRow(mixed $row): mixed {
		if (is_array($row) === false) {
			return $row;
		}

		if (array_is_list($row) === true) {
			return array_map([$this, 'configurationRow'], $row);
		}

		$row = array_diff_key($row, array_flip(self::WITHHELD_FROM_CONFIGURATION));
		foreach (self::NESTED_CONFIGURATION as $key) {
			if (is_array($row[$key] ?? null) === true) {
				$row[$key] = $this->configurationRow(row: $row[$key]);
			}
		}

		return $row;
	}//end configurationRow()

	/**
	 * One `@self` without the withheld fields.
	 *
	 * @param array<string, mixed> $self The `@self` envelope.
	 *
	 * @return array<string, mixed> The envelope without them.
	 */
	private function withoutPeople(array $self): array {
		foreach (self::WITHHELD as $field) {
			unset($self[$field]);
		}

		foreach (self::WITHHELD_WITHIN as $field => $keys) {
			if (is_array($self[$field] ?? null) === true) {
				$self[$field] = array_diff_key($self[$field], array_flip($keys));
			}
		}

		return $self;
	}//end withoutPeople()
}//end class
