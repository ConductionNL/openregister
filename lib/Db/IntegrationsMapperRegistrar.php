<?php

/**
 * IntegrationsMapperRegistrar: the entry that binds the Integrations area's mapper interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Support\SecretAtRest;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Binds the interfaces of the Integrations area's mappers: the links to
 * other Nextcloud apps, endpoints, consumers, sources, mappings, sync records
 * and webhooks.
 *
 * Application names this class only; the map lives in
 * IntegrationsMapperBindings, inside the area. An interface of a lower area
 * that an Integrations class implements is bound here as well, so no lower
 * registrar names it. The WebhookMapper, which needs a hand-written factory
 * for its secret-at-rest codec, is registered here too.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class IntegrationsMapperRegistrar {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = IntegrationsMapperBindings::BINDINGS;

	/**
	 * Register every binding, and the WebhookMapper factory, with the app container.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		DbRegistrar::bind(context: $context, bindings: IntegrationsMapperBindings::BINDINGS);
		$context->registerService(WebhookMapper::class, self::webhookMapper(...));
	}//end register()

	/**
	 * Build the WebhookMapper.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return WebhookMapper The mapper.
	 */
	private static function webhookMapper(ContainerInterface $container): WebhookMapper {
		return new WebhookMapper(
			db: $container->get('OCP\IDBConnection'),
			tenancy: new TenancyServices(
				organisationMapper: $container->get(OrganisationMapperInterface::class),
				userSession: $container->get('OCP\IUserSession'),
				groupManager: $container->get('OCP\IGroupManager')
			),
			appConfig: $container->get('OCP\IAppConfig'),
			secrets: new SecretAtRest(
				crypto: $container->get('OCP\Security\ICrypto'),
				logger: $container->get('Psr\Log\LoggerInterface')
			)
		);
	}//end webhookMapper()
}//end class
