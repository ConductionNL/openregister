<?php

/**
 * DoriathCredentialKeys: where the Doriath credential store keeps its registration.
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
 * The app-config and credential-manager keys the Doriath credential store and
 * the repair step that registers OpenRegister with Doriath share. The store
 * implements this interface, so DoriathCredentialStore::APP_CONFIG_APPLICATION_ID
 * and the others keep their values; callers outside the Service component read
 * them here instead of from the store.
 */
interface DoriathCredentialKeys {
	/**
	 * IAppConfig key (app `openregister`) holding the Doriath-assigned application UUID.
	 *
	 * @var string
	 */
	public const APP_CONFIG_APPLICATION_ID = 'doriath_application_id';

	/**
	 * IAppConfig key (app `openregister`) holding OpenRegister's own public key PEM.
	 *
	 * Public material only — needed by {@see DoriathCredentialStore::put()} for envelope encryption
	 * without a Doriath round-trip. The private counterpart NEVER lands here.
	 *
	 * @var string
	 */
	public const APP_CONFIG_PUBLIC_KEY_PEM = 'doriath_public_key_pem';

	/**
	 * SYSTEM-scoped `ICredentialsManager` key holding OpenRegister's private key PEM.
	 *
	 * Same system-scope idiom (`userId = ''`) as the per-app broker signing
	 * secrets in {@see CredentialAppTokenService}. The private key never leaves
	 * `ICredentialsManager`, is never logged, and never lands in `IAppConfig`.
	 *
	 * @var string
	 */
	public const PRIVATE_KEY_ID = 'openregister/doriath/private-key';
}//end interface
