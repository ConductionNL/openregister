<?php

/**
 * AuditTrailCollaborators: the audit helpers the audit-trail mapper builds, behind their interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Kernel\Contracts\Compliance\ElevationAttributionInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\TransitionInFlightInterface;
use OCA\OpenRegister\Service\AuditRetentionResolver;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Builds the helpers the audit-trail mapper runs on every row it writes.
 *
 * The mapper lives in the Db layer and builds these with `new` from the
 * container and app config it already holds. This class does exactly that and
 * hands the instance back typed against its interface, so the Db layer names
 * the interface and never the class that implements it. The elevation frame
 * is static request state; its read is delegated unchanged. The shared
 * services the mapper looks up are handed back as the container returns them,
 * so the caller's own `instanceof` and failure handling stay exactly as they
 * were. The lifecycle action context is asked for under its Compliance-owned
 * contract, TransitionInFlightInterface, which the lifecycle binds to the same
 * shared instance; the others under the same container id as before.
 *
 * Types only: what is attributed, merged or recorded stays with the caller.
 */
final class AuditTrailCollaborators {

	/**
	 * A fresh purpose attribution.
	 *
	 * @param ContainerInterface $container Resolves the request-scoped purpose context.
	 *
	 * @return PurposeAttributionInterface
	 */
	public static function purposeAttribution(ContainerInterface $container): PurposeAttributionInterface {
		return new PurposeAttribution(container: $container);
	}//end purposeAttribution()

	/**
	 * A fresh token attribution.
	 *
	 * @param ContainerInterface $container Resolves the request-scoped token context.
	 *
	 * @return TokenAttributionInterface
	 */
	public static function tokenAttribution(ContainerInterface $container): TokenAttributionInterface {
		return new TokenAttribution(container: $container);
	}//end tokenAttribution()

	/**
	 * A fresh organisation-context attribution.
	 *
	 * @return OrganisationContextAttributionInterface
	 */
	public static function organisationContextAttribution(): OrganisationContextAttributionInterface {
		return new OrganisationContextAttribution();
	}//end organisationContextAttribution()

	/**
	 * A fresh audit aggregation service over the given app config.
	 *
	 * @param IAppConfig $appConfig The app config the window is read from.
	 *
	 * @return AuditAggregationServiceInterface
	 */
	public static function aggregation(IAppConfig $appConfig): AuditAggregationServiceInterface {
		return new AuditAggregationService(appConfig: $appConfig);
	}//end aggregation()

	/**
	 * The elevation attribution of the open object-action frame, if it used the row's register, schema and verb.
	 *
	 * Asked through the container like the other ambient frames the mapper
	 * reads: an action run (Processes) answers the Compliance-owned port. When
	 * nothing answers, no elevation is stamped.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param AuditTrail         $row       The audit row being written.
	 *
	 * @return array{elevatedBy: string, triggeredBy: string|null, runAs: string}|null The attribution.
	 */
	public static function elevationAttribution(ContainerInterface $container, AuditTrail $row): ?array {
		try {
			return $container->get(ElevationAttributionInterface::class)->attributionFor(row: $row);
		} catch (Throwable) {
			return null;
		}
	}//end elevationAttribution()

	/**
	 * The audit retention resolver, exactly as the container returns it.
	 *
	 * @param ContainerInterface $container The container the mapper holds.
	 *
	 * @return mixed The shared AuditRetentionResolverInterface instance.
	 */
	public static function retentionResolverFrom(ContainerInterface $container): mixed {
		return $container->get(AuditRetentionResolver::class);
	}//end retentionResolverFrom()

	/**
	 * The request's lifecycle action context, exactly as the container returns it.
	 *
	 * @param ContainerInterface $container The container the mapper holds.
	 *
	 * @return mixed The shared TransitionInFlightInterface instance (the lifecycle action context).
	 */
	public static function lifecycleActionContextFrom(ContainerInterface $container): mixed {
		return $container->get(TransitionInFlightInterface::class);
	}//end lifecycleActionContextFrom()
}//end class
