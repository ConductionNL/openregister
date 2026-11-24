<?php

/**
 * PlatformServiceBindings: the map of the Platform area's service interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\ActionCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ActionRightCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ActionRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\OutboundBreakerInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RouteCallersInterface;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorderInterface;
use OCA\OpenRegister\Service\ApiCaller\CallerPolicy;
use OCA\OpenRegister\Service\ApiCaller\CallerPolicyInterface;
use OCA\OpenRegister\Service\ApiCaller\CallerRateLimiter;
use OCA\OpenRegister\Service\ApiCaller\CallerRateLimiterInterface;
use OCA\OpenRegister\Service\ApiCaller\RouteCallers;
use OCA\OpenRegister\Service\Chat\ChatProxyHandler;
use OCA\OpenRegister\Service\Chat\ChatProxyHandlerInterface;
use OCA\OpenRegister\Service\Resilience\OutboundBreaker;

/**
 * Interface => service map of the Platform area, behind PlatformServiceRegistrar.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class PlatformServiceBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		ActionCheckInterface::class                  => OpenRegisterActionAuthService::class,
		ActionRightCheckInterface::class             => OpenRegisterActionAuthService::class,
		ActionRightsCatalogueInterface::class        => ActionRightsCatalogue::class,
		ActionRightsInterface::class                 => ActionRightsCatalogue::class,
		ApiCallRecorderInterface::class              => ApiCallRecorder::class,
		CallerPolicyInterface::class                 => CallerPolicy::class,
		CallerRateLimiterInterface::class            => CallerRateLimiter::class,
		ChatProxyHandlerInterface::class             => ChatProxyHandler::class,
		LanguageServiceInterface::class              => LanguageService::class,
		OutboundBreakerInterface::class              => OutboundBreaker::class,
		\OCA\OpenRegister\Kernel\Contracts\DependencyBreakerInterface::class => \OCA\OpenRegister\Service\Resilience\DependencyBreaker::class,
		\OCA\OpenRegister\Kernel\Contracts\DependencyConsoleInterface::class => \OCA\OpenRegister\Service\Resilience\DependencyConsole::class,
		RouteCallersInterface::class                 => RouteCallers::class,
		TranslationProjectionServiceInterface::class => TranslationProjectionService::class,
		TranslationStatusServiceInterface::class     => TranslationStatusService::class,
	];
}//end class
