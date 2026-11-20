<?php

/**
 * ComplianceServiceBindings: the map of the Compliance area's service interfaces.
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

use OCA\OpenRegister\Kernel\Contracts\Core\AuthRateLimitCeilingInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\CascadeRetentionCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectArchivalMetadataInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SearchTrailRecorderInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeGuardInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadableObjectHistoryInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RecordArchivalReadingInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterAccessChangeLogInterface;
use OCA\OpenRegister\Service\Archival\RecordArchivalReading;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;

/**
 * Interface => service map of the Compliance area, behind ComplianceServiceRegistrar.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ComplianceServiceBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AuthRateLimitCeilingInterface::class    => HardeningPolicy::class,
		CascadeRetentionCheckInterface::class   => ArchivalRetentionGuard::class,
		ObjectArchivalMetadataInterface::class  => TmloService::class,
		ReadPurposeGuardInterface::class        => PurposeGuard::class,
		ReadableObjectHistoryInterface::class   => ObjectHistoryForReaders::class,
		RecordArchivalReadingInterface::class   => RecordArchivalReading::class,
		RegisterAccessChangeLogInterface::class => AuthorizationAuditService::class,
		SearchTrailRecorderInterface::class     => SearchTrailService::class,
	];
}//end class
