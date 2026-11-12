<?php

/**
 * CredentialUpdateRequestInterface: what callers outside lib/Service use of CredentialUpdateRequest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The part of CredentialUpdateRequest that code outside lib/Service uses.
 */
interface CredentialUpdateRequestInterface {

	/**
	 * Apply the metadata the request offers, leaving the rest of the object alone.
	 *
	 * @param array<string, mixed> $data The stored credential's property bag.
	 *
	 * @return array<string, mixed> The property bag with the request's edits applied.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function applyTo(array $data): array;

	/**
	 * Whether this update would move a credential's pinned host.
	 *
	 * @param array<string, mixed> $data The stored credential's property bag.
	 *
	 * @return boolean True when the request proposes a different host.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	public function wouldRepointHost(array $data): bool;

	/**
	 * Whether the updated property bag breaks a length bound of the schema.
	 *
	 * Counted in characters, as JSON Schema's maxLength is, not in bytes.
	 *
	 * @param array<string, mixed> $data The property bag with the request's edits applied.
	 *
	 * @return boolean True when the name or an allowed app id is too long.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function exceedsBounds(array $data): bool;

	/**
	 * The rotated secret this request carries, or null when it carries none.
	 *
	 * The trim is not cosmetic. This rotation path writes straight to the vault and
	 * does NOT go through {@see CredentialBrokerService::mint()}, so it needs its own
	 * (credential-broker-upstream-diagnostics D3): a copy-pasted secret with a
	 * trailing newline previously reached the vault byte for byte and then failed
	 * header injection at call time with no usable diagnostic.
	 *
	 * @return string|null The trimmed secret, or null.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function rotatedSecret(): ?string;
}//end interface
