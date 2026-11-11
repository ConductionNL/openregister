<?php

/**
 * OpenRegister ContactLinkJson
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ContactLink
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

namespace OCA\OpenRegister\Db\ContactLink;

use OCA\OpenRegister\Db\TypedFieldsJson;

/**
 * The JSON form of a contact link.
 *
 * `metadata` is stored as JSON-encoded text and is decoded for the consumer,
 * so the registry / Tab can iterate keys naturally; a corrupt row surfaces as
 * null rather than blowing up. Validity dates are plain dates.
 *
 * @spec exclude contact link plumbing moved out of the entity, not a capability of its own
 */
final class ContactLinkJson {

	/**
	 * The serialised link.
	 *
	 * @param array<string, mixed> $values  The link's field values by name, with its field types under `fieldTypes`.
	 * @param array<string, mixed> $derived The link's `kind` and `active`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude contact link plumbing moved out of the entity, not a capability of its own
	 */
	public function serialise(array $values, array $derived): array {
		$typed = new TypedFieldsJson();
		$json = array_merge(
			$typed->serialise(fieldTypes: $values['fieldTypes'], values: $values),
			[
				'metadata' => $this->metadata(stored: $values['metadata']),
				'validFrom' => $values['validFrom']?->format('Y-m-d'),
				'validUntil' => $values['validUntil']?->format('Y-m-d'),
				'primaryParty' => ($values['primaryParty'] === true),
			]
		);
		return $typed->inserted(json: $json, entriesAfter: ['linkedAt' => ['kind' => $derived['kind']], 'note' => ['active' => $derived['active']]]);
	}//end serialise()

	/**
	 * The decoded metadata, or null when it is absent, corrupt or not an array.
	 *
	 * @param string|null $stored The stored JSON text.
	 *
	 * @return array<mixed>|null
	 */
	private function metadata(string|null $stored): array|null {
		if ($stored === null || $stored === '') {
			return null;
		}

		$decoded = $this->decoded(stored: $stored);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end metadata()

	/**
	 * The decoded JSON text, or null when it is corrupt.
	 *
	 * @param string $stored The stored JSON text.
	 *
	 * @return mixed
	 */
	private function decoded(string $stored): mixed {
		try {
			return json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return null;
		}
	}//end decoded()
}//end class
