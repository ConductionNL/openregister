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
	 * @var array<string, string> `@self` field => key inside it
	 */
	private const WITHHELD_WITHIN = [
		'locked'   => 'user',
		'deleted'  => 'deletedBy',
		'archived' => 'by',
		'frozen'   => 'by',
		'register' => 'owner',
		'schema'   => 'owner',
	];

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

		foreach (self::WITHHELD_WITHIN as $field => $key) {
			if (is_array($self[$field] ?? null) === true) {
				unset($self[$field][$key]);
			}
		}

		return $self;
	}//end withoutPeople()
}//end class
