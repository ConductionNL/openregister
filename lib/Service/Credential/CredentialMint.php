<?php

/**
 * CredentialMint — what a new brokered credential is: its name, provider, owner and connection metadata.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The required half of a mint (ADR-011 Rule 2); {@see CredentialMintOptions} carries the optional half.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class CredentialMint {
	/**
	 * Constructor.
	 *
	 * @param string               $name     The human-readable credential name (non-empty).
	 * @param string               $provider The provider identifier (the caller validates it against the catalogue).
	 * @param string               $owner    The owning user's UID (asserted by the trusted caller; never request input).
	 * @param array<string, mixed> $metadata Additional NON-SECRET connection properties, reduced to the allow-list
	 *                                       of {@see CredentialBrokerService::connectionMetadata()}.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $provider,
		public readonly string $owner,
		public readonly array $metadata=[],
	) {
	}//end __construct()
}//end class
