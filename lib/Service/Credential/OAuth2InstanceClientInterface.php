<?php

/**
 * OAuth2InstanceClientInterface: what callers outside the component use of OAuth2InstanceClient.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The claim key the OAuth2 endpoints read from a minted instance client.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface OAuth2InstanceClientInterface {

	/**
	 * Claims key naming the client credential this call minted.
	 *
	 * Set only when ensure() registered a new client, never for one it reused,
	 * so a caller whose later step fails knows exactly what to remove. It is not
	 * a claim: the caller takes it out before the claims are signed.
	 *
	 * @var string
	 */
	public const MINTED_KEY = '_minted';
}//end interface
