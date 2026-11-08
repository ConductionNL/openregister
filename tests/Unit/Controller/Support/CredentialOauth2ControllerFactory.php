<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\CredentialOauth2Controller;

/**
 * Builds CredentialOauth2Controller from its services as separate arguments, wrapped into its constructor bundles.
 */
final class CredentialOauth2ControllerFactory {
	/**
	 * The constructor arguments of CredentialOauth2Controller.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param OAuth2ConnectService $connect Constructor argument.
	 * @param OAuth2StateService $states Constructor argument.
	 * @param OAuth2RelayGuard $relay Constructor argument.
	 * @param OAuth2ConnectionRepository $connections Constructor argument.
	 * @param OAuth2Endpoints $endpoints Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IThrottler $throttler Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Credential\OAuth2ConnectService $connect,
		\OCA\OpenRegister\Service\Credential\OAuth2StateService $states,
		\OCA\OpenRegister\Service\Credential\OAuth2RelayGuard $relay,
		\OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository $connections,
		\OCA\OpenRegister\Service\Credential\OAuth2Endpoints $endpoints,
		\OCP\IUserSession $userSession,
		\OCP\Security\Bruteforce\IThrottler $throttler,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\CredentialOauth2ControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\CredentialOauth2Mappers(
					connections: $connections,
				),
				domain: new \OCA\OpenRegister\Controller\Support\CredentialOauth2Domain(
					connect: $connect,
					states: $states,
					relay: $relay,
					endpoints: $endpoints,
				),
				platform: new \OCA\OpenRegister\Controller\Support\AccessLinkPlatform(
					userSession: $userSession,
					throttler: $throttler,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build CredentialOauth2Controller; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return CredentialOauth2Controller
	 */
	public static function create(mixed ...$args): CredentialOauth2Controller {
		return new CredentialOauth2Controller(...self::args(...$args));
	}
}
