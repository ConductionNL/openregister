<?php

/**
 * OpenRegister Credential — Connected Account
 *
 * The provider account an OAuth2 connection speaks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Credential
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

/**
 * Whose account a connection was made with, as the provider names it.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-a-connection-records-the-account-it-speaks-for
 */
final class ConnectedAccount {
	/**
	 * Constructor.
	 *
	 * @param string $id          The provider's own identifier for the account.
	 * @param string $handle      The account handle as the provider spells it.
	 * @param string $displayName The account's display name.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $handle,
		public readonly string $displayName,
	) {
	}//end __construct()

	/**
	 * The account as the token set stores it.
	 *
	 * @return array{id: string, handle: string, displayName: string}
	 */
	public function toArray(): array {
		return ['id' => $this->id, 'handle' => $this->handle, 'displayName' => $this->displayName];
	}//end toArray()
}//end class
