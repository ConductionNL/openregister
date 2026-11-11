<?php

/**
 * OpenRegister SourceJson
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Source;

/**
 * The JSON form of a source, built from its field values.
 *
 * The encrypted credential blob is never exposed; only whether one is configured.
 *
 * No strict_types: moved out of Source, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude source plumbing moved out of the entity, not a capability of its own
 */
final class SourceJson {

	/**
	 * The serialised source, in its fixed key order.
	 *
	 * @param array<string, mixed> $fields The source's field values by name.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude source plumbing moved out of the entity, not a capability of its own
	 */
	public function serialise(array $fields): array {
		return array_merge($this->identity(fields: $fields), $this->sync(fields: $fields), $this->target(fields: $fields));
	}//end serialise()

	/**
	 * Id, names, connection and tenancy.
	 *
	 * @param array<string, mixed> $fields The source's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function identity(array $fields): array {
		return [
			'id' => $fields['id'],
			'uuid' => $fields['uuid'],
			'title' => $fields['title'],
			'version' => $fields['version'],
			'description' => $fields['description'],
			'databaseUrl' => $fields['databaseUrl'],
			'type' => $fields['type'],
			'organisation' => $fields['organisation'],
		];
	}//end identity()

	/**
	 * Synchronisation state and authentication.
	 *
	 * @param array<string, mixed> $fields The source's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function sync(array $fields): array {
		return [
			'syncEnabled' => $fields['syncEnabled'],
			'syncSchedule' => $fields['syncSchedule'],
			'syncInterval' => $fields['syncInterval'],
			'lastSyncDate' => $fields['lastSyncDate']?->format('c'),
			'lastSyncStatus' => $fields['lastSyncStatus'],
			'lastSyncToken' => $fields['lastSyncToken'],
			'authType' => $fields['authType'],
			'authConfigured' => ($fields['authConfig'] !== null && $fields['authConfig'] !== []),
		];
	}//end sync()

	/**
	 * Mapping, target, strategies and timestamps.
	 *
	 * @param array<string, mixed> $fields The source's field values by name.
	 *
	 * @return array<string, mixed>
	 */
	private function target(array $fields): array {
		return [
			'mappingId' => $fields['mappingId'],
			'targetRegister' => $fields['targetRegister'],
			'targetSchema' => $fields['targetSchema'],
			'conflictStrategy' => $fields['conflictStrategy'],
			'deleteStrategy' => $fields['deleteStrategy'],
			'batchSize' => $fields['batchSize'],
			'updated' => $fields['updated']?->format('c'),
			'created' => $fields['created']?->format('c'),
			'managedByConfiguration' => null,
		];
	}//end target()
}//end class
