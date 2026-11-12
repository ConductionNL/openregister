<?php

/**
 * TokenSetAddress — where one credential's token set is kept.
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
 * A credential's id and custody scope: together they name the one stored token set.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
 */
final readonly class TokenSetAddress {
	/**
	 * Constructor.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $scope        The credential scope (`personal`|`organisation`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public function __construct(
		public string $credentialId,
		public string $scope,
	) {
	}//end __construct()
}//end class
