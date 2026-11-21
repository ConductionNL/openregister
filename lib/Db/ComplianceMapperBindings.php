<?php

/**
 * ComplianceMapperBindings: the map of the Compliance area's mapper interfaces.
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

use OCA\OpenRegister\Db\AuditTrail\AuditDiffStore;
use OCA\OpenRegister\Db\AuditTrail\AuditDiffStoreInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RetainingActivityResolverInterface;

/**
 * Interface => mapper map of the Compliance area, behind ComplianceMapperRegistrar.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ComplianceMapperBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AuditDiffStoreInterface::class              => AuditDiffStore::class,
		AuditTrailMapperInterface::class            => AuditTrailMapper::class,
		AuditTrailPageQueryInterface::class         => AuditTrailPageQuery::class,
		ContentReportMapperInterface::class         => ContentReportMapper::class,
		ErasurePreviewMapperInterface::class        => ErasurePreviewMapper::class,
		GdprEntityMapperInterface::class            => GdprEntityMapper::class,
		ProcessingLogMapperInterface::class         => ProcessingLogMapper::class,
		ProcessingPurposeMapperInterface::class     => ProcessingPurposeMapper::class,
		QualityAuditMapperInterface::class          => QualityAuditMapper::class,
		QualityAuditRunMapperInterface::class       => QualityAuditRunMapper::class,
		RetainingActivityResolverInterface::class   => VerwerkingsactiviteitMapper::class,
		SearchTrailCleanupInterface::class          => SearchTrailMapper::class,
		SearchTrailMapperInterface::class           => SearchTrailMapper::class,
		SubjectExportMapperInterface::class         => SubjectExportMapper::class,
		VerwerkingsactiviteitMapperInterface::class => VerwerkingsactiviteitMapper::class,
	];
}//end class
