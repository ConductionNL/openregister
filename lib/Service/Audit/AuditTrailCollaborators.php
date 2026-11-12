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

use OCA\OpenRegister\Service\AuditRetentionResolver;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Builds the helpers the audit-trail mapper runs on every row it writes.
 *
 * The mapper lives in the Db layer and builds these with `new` from the
 * container and app config it already holds. This class does exactly that and
 * hands the instance back typed against its interface, so the Db layer names
 * the interface and never the class that implements it. The elevation frame
 * is static request state; its read is delegated unchanged. The shared
 * services the mapper looks up are asked for under the same container id as
 * before, and handed back as the container returns them, so the caller's own
 * `instanceof` and failure handling stay exactly as they were.
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
	 * The elevation attribution of the open object-action frame, if it used this schema and verb.
	 *
	 * @param string|int|null $schemaId The schema of the audited object.
	 * @param string          $verb     The audited action.
	 *
	 * @return array{elevatedBy: string, triggeredBy: string|null, runAs: string}|null The attribution.
	 */
	public static function elevationAttribution(string|int|null $schemaId, string $verb): ?array {
		return ObjectActionElevationScope::attributionFor(schemaId: $schemaId, verb: $verb);
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
	 * @return mixed The shared LifecycleActionContextInterface instance.
	 */
	public static function lifecycleActionContextFrom(ContainerInterface $container): mixed {
		return $container->get(LifecycleActionContext::class);
	}//end lifecycleActionContextFrom()
}//end class
