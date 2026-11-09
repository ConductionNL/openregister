<?php

/**
 * OAuth2RefreshTarget — the credential one refresh works on, and as whom.
 *
 * The private steps of {@see OAuth2RefreshService} all pass the same values
 * along (the credential's data, its provider entry, its id, its custody scope and
 * the asserted acting user). Bundling them keeps every step's signature about what
 * that step adds, not about what it forwards. Non-secret: no token is ever held here.
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
 * The credential a refresh rotates.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
 */
final readonly class OAuth2RefreshTarget {
	/**
	 * The credential UUID.
	 *
	 * @var string
	 */
	public string $credentialId;

	/**
	 * The credential scope (`personal`|`organisation`).
	 *
	 * @var string
	 */
	public string $scope;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param TokenSetAddress $address The credential UUID and its scope (`personal`|`organisation`).
	 * @param string|null $actingUserId The asserted user for a sessionless caller.
	 *
	 * @return void
	 */
	public function __construct(
		public array $credential,
		public array $provider,
		TokenSetAddress $address,
		public ?string $actingUserId,
	) {
		$this->credentialId = $address->credentialId;
		$this->scope = $address->scope;
	}//end __construct()
}//end class
