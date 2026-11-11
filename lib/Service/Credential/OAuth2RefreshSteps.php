<?php

/**
 * The token exchange of an OAuth2 refresh and the notice when it cannot be renewed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * A dependency bundle of {@see OAuth2RefreshService}.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md
 */
final class OAuth2RefreshSteps {
	/**
	 * Bundle the services.
	 *
	 * @param OAuth2RefreshExchange    $exchange       Spends the refresh token and classifies the answer.
	 * @param CredentialRelinkNotifier $relinkNotifier Announces a lost grant to the instance and its owner.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md
	 */
	public function __construct(
		public readonly OAuth2RefreshExchange $exchange,
		public readonly CredentialRelinkNotifier $relinkNotifier,
	) {
	}//end __construct()
}//end class
