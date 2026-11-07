<?php

/**
 * OpenRegister RegisterJson
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Register
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

namespace OCA\OpenRegister\Db\Register;

/**
 * The JSON form of a register, built from its field values.
 *
 * @spec exclude register plumbing moved out of the entity, not a capability of its own
 */
final class RegisterJson {

	/**
	 * The linked Nextcloud items, serialised under an underscore prefix.
	 *
	 * @var string[]
	 */
	private const LINKS = ['mail', 'contacts', 'notes', 'todos', 'calendar', 'talk', 'deck'];

	/**
	 * The serialised register, in its fixed key order.
	 *
	 * @param array<string, mixed> $fields The register's field values by name.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude register plumbing moved out of the entity, not a capability of its own
	 */
	public function serialise(array $fields): array {
		return array_merge(
			$this->identity(fields: $fields),
			$this->storage(fields: $fields),
			$this->ownership(fields: $fields),
			$this->accounting(fields: $fields),
			$this->links(fields: $fields)
		);
	}//end serialise()

	/**
	 * Id, names and description.
	 *
	 * @param array<string, mixed> $fields The register's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function identity(array $fields): array {
		return [
			'id' => $fields['id'],
			'uuid' => $fields['uuid'],
			'slug' => $fields['slug'],
			'title' => $fields['title'],
			'version' => $fields['version'],
			'description' => $fields['description'],
		];
	}//end identity()

	/**
	 * Schemas, storage location and timestamps.
	 *
	 * @param array<string, mixed> $fields The register's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function storage(array $fields): array {
		return [
			'schemas' => (new RegisterSchemaIds())->idsOnly(schemas: ($fields['schemas'] ?? [])),
			'source' => $fields['source'],
			'tablePrefix' => $fields['tablePrefix'],
			'folder' => $fields['folder'],
			'updated' => $fields['updated']?->format('c'),
			'created' => $fields['created']?->format('c'),
		];
	}//end storage()

	/**
	 * Owner, tenancy, access and configuration.
	 *
	 * @param array<string, mixed> $fields The register's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function ownership(array $fields): array {
		return [
			'owner' => $fields['owner'],
			'application' => $fields['application'],
			'organisation' => $fields['organisation'],
			'authorization' => $fields['authorization'],
			'sharedWith' => ($fields['sharedWith'] ?? []),
			'groups' => ($fields['groups'] ?? []),
			'type' => $fields['type'],
			'languages' => $fields['languages'],
			'configuration' => $fields['configuration'],
		];
	}//end ownership()

	/**
	 * Quota (set via admin configuration), usage and the deletion time.
	 *
	 * @param array<string, mixed> $fields The register's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function accounting(array $fields): array {
		return [
			'quota' => ['storage' => null, 'bandwidth' => null, 'requests' => null, 'users' => null, 'groups' => null],
			'usage' => [
				'storage' => 0,
				'bandwidth' => 0,
				'requests' => 0,
				'users' => 0,
				'groups' => count(($fields['groups'] ?? [])),
			],
			'deleted' => $fields['deleted']?->format('c'),
		];
	}//end accounting()

	/**
	 * The linked Nextcloud items.
	 *
	 * @param array<string, mixed> $fields The register's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function links(array $fields): array {
		$links = [];
		foreach (self::LINKS as $link) {
			$links['_' . $link] = $fields[$link];
		}

		return $links;
	}//end links()
}//end class
