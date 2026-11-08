<?php

/**
 * C12 cases: the audit, tenancy and field-level helpers the Db layer reaches.
 *
 * The audit-trail mapper and the tenancy traits build them through
 * AuditTrailCollaborators / TenancyCollaborators, or resolve them from the
 * container by their interface; the encryption command and the settings
 * controller type against the interface. ObjectActionElevationScope has no
 * interface: its request frame is static and only its read is delegated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service;
use OCA\OpenRegister\Service\Audit;
use OCA\OpenRegister\Service\Lifecycle;
use OCA\OpenRegister\Service\ObjectAction;

return [
	'classes' => [
		Audit\AuditAggregationService::class => Audit\AuditAggregationServiceInterface::class,
		Audit\OrganisationContextAttribution::class => Audit\OrganisationContextAttributionInterface::class,
		Audit\PurposeAttribution::class => Audit\PurposeAttributionInterface::class,
		Audit\TokenAttribution::class => Audit\TokenAttributionInterface::class,
		Lifecycle\LifecycleActionContext::class => Lifecycle\LifecycleActionContextInterface::class,
		ObjectAction\ObjectActionElevationScope::class => null,
		Service\AuditRetentionResolver::class => Service\AuditRetentionResolverInterface::class,
		Service\FieldEncryptionHandler::class => Service\FieldEncryptionHandlerInterface::class,
		Service\PropertyRbacHandler::class => Service\PropertyRbacHandlerInterface::class,
		Service\SharedMasterDataService::class => Service\SharedMasterDataServiceInterface::class,
		Service\TenantLogRedactor::class => Service\TenantLogRedactorInterface::class,
	],
	'kept' => [],
];
