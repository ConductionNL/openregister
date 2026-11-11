<?php

/**
 * OpenRegister source payload
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\Security\ICrypto;

/**
 * Turns a submitted source into the array the mapper persists.
 *
 * Internal (`_`-prefixed) keys never reach the row, and the credential custody
 * split holds on both write paths: LEGACY harvest sources keep encrypting their
 * full databaseUrl at rest with ICrypto; `type: database` (virtual register)
 * sources store only NON-SECRET connection parts in authConfig and custody the
 * password behind the CredentialStore seam, referenced by a `credential` UUID —
 * a plaintext password is never persisted on the row (dbal-virtual-registers D1).
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SourcePayload {
	/**
	 * Fields a client may not change on an existing source.
	 *
	 * @var array<int, string>
	 */
	private const IMMUTABLE = ['id', 'organisation', 'owner', 'created'];

	/**
	 * Constructor.
	 *
	 * @param ICrypto $crypto Encrypts a legacy databaseUrl at rest.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * The data a new source is created from.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return array<string, mixed> The data to persist.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function forCreate(array $data): array {
		$data = $this->withoutInternalKeys(data: $data);

		// Remove ID if present to ensure a new record is created.
		if (($data['id'] ?? null) !== null) {
			unset($data['id']);
		}

		return $this->secured(data: $data);
	}//end forCreate()

	/**
	 * The data an existing source is updated with.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return array<string, mixed> The data to persist.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function forUpdate(array $data): array {
		$data = $this->withoutInternalKeys(data: $data);

		// Remove immutable fields to prevent tampering.
		foreach (self::IMMUTABLE as $field) {
			unset($data[$field]);
		}

		return $this->secured(data: $data);
	}//end forUpdate()

	/**
	 * Drop every parameter whose key starts with '_'.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return array<string, mixed> The parameters without internal keys.
	 */
	private function withoutInternalKeys(array $data): array {
		return array_filter(
			$data,
			static fn (int|string $key): bool => str_starts_with((string)$key, '_') === false,
			ARRAY_FILTER_USE_KEY
		);
	}//end withoutInternalKeys()

	/**
	 * Apply the custody split, then encrypt a legacy databaseUrl at rest.
	 *
	 * @param array<string, mixed> $data The source data.
	 *
	 * @return array<string, mixed> The data safe to persist.
	 */
	private function secured(array $data): array {
		$data = $this->sanitizeDatabaseSourceData(data: $data);

		if (isset($data['databaseUrl']) === true && $data['databaseUrl'] !== '') {
			$data['databaseUrl'] = $this->crypto->encrypt((string)$data['databaseUrl']);
		}

		return $data;
	}//end secured()

	/**
	 * Enforce the credential custody split for `type: database` sources.
	 *
	 * A virtual-register database source must never persist a plaintext secret:
	 * any `password`/`secret` key submitted inside `authConfig` is stripped, and
	 * `databaseUrl` (the legacy ICrypto-encrypted harvest field) is cleared so
	 * the two paths cannot mix. The password belongs behind the CredentialStore
	 * seam, referenced by the `authConfig.credential` UUID (design D1).
	 *
	 * @param array<string, mixed> $data The submitted source data.
	 *
	 * @return array<string, mixed> The sanitised source data.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function sanitizeDatabaseSourceData(array $data): array {
		if ((string)($data['type'] ?? '') !== 'database') {
			return $data;
		}

		if (isset($data['authConfig']) === true && is_array($data['authConfig']) === true) {
			unset($data['authConfig']['password'], $data['authConfig']['secret']);
		}

		// Clear rather than unset: the sources table declares database_url
		// NOT NULL, so an absent value fails the INSERT with a 23502. An empty
		// string satisfies the constraint and carries no secret.
		$data['databaseUrl'] = '';

		return $data;
	}//end sanitizeDatabaseSourceData()
}//end class
