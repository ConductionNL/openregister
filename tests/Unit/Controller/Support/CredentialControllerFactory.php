<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\CredentialController;

/**
 * Builds CredentialController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class CredentialControllerFactory {
	/**
	 * The constructor arguments of CredentialController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param CredentialStore $credentialStore Constructor argument.
	 * @param ProviderCatalogue $catalogue Constructor argument.
	 * @param CredentialBrokerService $broker Constructor argument.
	 * @param CredentialAppTokenService $tokenService Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param SharePrincipalDeriver $shareDeriver Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ?IL10N $l10n Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Service\Credential\CredentialStore $credentialStore,
		\OCA\OpenRegister\Service\Credential\ProviderCatalogue $catalogue,
		\OCA\OpenRegister\Service\Credential\CredentialBrokerService $broker,
		\OCA\OpenRegister\Service\Credential\CredentialAppTokenService $tokenService,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver $shareDeriver,
		\Psr\Log\LoggerInterface $logger,
		?\OCP\IL10N $l10n =null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\CredentialControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				vault: new \OCA\OpenRegister\Controller\Support\CredentialVault(
					objectService: $objectService,
					credentialStore: $credentialStore,
					catalogue: $catalogue,
					broker: $broker,
				),
				tokens: new \OCA\OpenRegister\Controller\Support\CredentialTokens(
					tokenService: $tokenService,
					organisationService: $organisationService,
					shareDeriver: $shareDeriver,
				),
				platform: new \OCA\OpenRegister\Controller\Support\CredentialPlatform(
					l10n: $l10n,
				),
			),
		];
	}

	/**
	 * Build CredentialController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return CredentialController
	 */
	public static function create(mixed ...$args): CredentialController {
		return new CredentialController(...self::args(...$args));
	}
}
