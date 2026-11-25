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
use OCA\OpenRegister\Kernel\Contracts\DetectorRecordInterface;
use OCA\OpenRegister\Service\Anonymisation\DetectorRecord;
use OCA\OpenRegister\Kernel\Contracts\Core\CascadeRetentionCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectArchivalMetadataInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeGuardInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadableObjectHistoryInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RecordArchivalReadingInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterAccessChangeLogInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SearchTrailRecorderInterface;
use OCA\OpenRegister\Kernel\Contracts\ProcessingCatalogueSeedInterface;
use OCA\OpenRegister\Kernel\Contracts\SystemAuditLineInterface;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendServiceInterface;
use OCA\OpenRegister\Service\Anonymisation\AnonymiserRequestInterface;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\ArchivalNominationServiceInterface;
use OCA\OpenRegister\Service\Archival\ArchivalNominationTriggerInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\ArchiveReferenceReaderInterface;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndex;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndexInterface;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\DestructionListCreatorInterface;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionListRepositoryInterface;
use OCA\OpenRegister\Service\Archival\DestructionReviewReminderInterface;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionReviewServiceInterface;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\DestructionServiceInterface;
use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\LegalHoldServiceInterface;
use OCA\OpenRegister\Service\Archival\RecordArchivalReading;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\Archival\RetentionEvaluatorInterface;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeServiceInterface;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Archival\SelectielijstImportServiceInterface;
use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolver;
use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolverInterface;
use OCA\OpenRegister\Service\Audit\AuditAggregationService;
use OCA\OpenRegister\Service\Audit\AuditAggregationServiceInterface;
use OCA\OpenRegister\Service\Audit\AuditEntryWriter;
use OCA\OpenRegister\Service\Audit\AuditResealInterface;
use OCA\OpenRegister\Service\Audit\AuditSink;
use OCA\OpenRegister\Service\Audit\AuditSinkInterface;
use OCA\OpenRegister\Service\Audit\AuditSinkShipperInterface;
use OCA\OpenRegister\Service\Audit\AuditSinkStatus;
use OCA\OpenRegister\Service\Audit\AuditSinkStatusInterface;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\AuditTrailPageServiceInterface;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCA\OpenRegister\Service\Audit\ContentReportServiceInterface;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReadersInterface;
use OCA\OpenRegister\Service\Audit\OrganisationContextAttribution;
use OCA\OpenRegister\Service\Audit\OrganisationContextAttributionInterface;
use OCA\OpenRegister\Service\Audit\PurposeAttribution;
use OCA\OpenRegister\Service\Audit\PurposeAttributionInterface;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeGuardInterface;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCA\OpenRegister\Service\Audit\PurposeRegistryInterface;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailListerInterface;
use OCA\OpenRegister\Service\Audit\TokenAttribution;
use OCA\OpenRegister\Service\Audit\TokenAttributionInterface;
use OCA\OpenRegister\Service\Edepot\EdepotTransferAttemptInterface;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\EdepotTransferServiceInterface;
use OCA\OpenRegister\Service\Edepot\TransferListRunInterface;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferListServiceInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\TransferRecordServiceInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordStoreInterface;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransportInterface;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControlInterface;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessorInterface;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestServiceInterface;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectorInterface;
use OCA\OpenRegister\Service\Gdpr\ProcessingCatalogueSeeder;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStoreInterface;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunnerInterface;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportAssemblerInterface;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistryInterface;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolverInterface;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistryInterface;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepInterface;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService;
use OCA\OpenRegister\Service\Hardening\AllowedOriginsInterface;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\ElevationServiceInterface;
use OCA\OpenRegister\Service\Hardening\HardeningFloorsInterface;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\HardeningReportServiceInterface;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsServiceInterface;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCA\OpenRegister\Service\Hardening\StatementServiceInterface;
use OCA\OpenRegister\Service\Object\CorrectionService;
use OCA\OpenRegister\Service\Object\CorrectionServiceInterface;

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
		AuthRateLimitCeilingInterface::class           => HardeningPolicy::class,
		CascadeRetentionCheckInterface::class          => ArchivalRetentionGuard::class,
		DetectorRecordInterface::class                 => DetectorRecord::class,
		ProcessingCatalogueSeedInterface::class        => ProcessingCatalogueSeeder::class,
		ObjectArchivalMetadataInterface::class         => TmloService::class,
		ReadPurposeGuardInterface::class               => PurposeGuard::class,
		ReadableObjectHistoryInterface::class          => ObjectHistoryForReaders::class,
		RecordArchivalReadingInterface::class          => RecordArchivalReading::class,
		RegisterAccessChangeLogInterface::class        => AuthorizationAuditService::class,
		SearchTrailRecorderInterface::class            => SearchTrailService::class,
		SystemAuditLineInterface::class                => AuditEntryWriter::class,
		AnonymisationBackendServiceInterface::class    => AnonymisationBackendService::class,
		AnonymiserRequestInterface::class              => AnonymisationBackendService::class,
		ArchivalNominationServiceInterface::class      => ArchivalNominationService::class,
		ArchivalNominationTriggerInterface::class      => ArchivalNominationService::class,
		AuditSinkInterface::class                      => AuditSink::class,
		AuditSinkShipperInterface::class               => AuditSink::class,
		AuditSinkStatusInterface::class                => AuditSinkStatus::class,
		AuditTrailPageServiceInterface::class          => AuditTrailPageService::class,
		AuditHashServiceInterface::class               => AuditHashService::class,
		AuditResealInterface::class                    => AuditHashService::class,
		AuditQueryServiceInterface::class              => AuditQueryService::class,
		DestructionListCreatorInterface::class         => DestructionListCreator::class,
		DestructionServiceInterface::class             => DestructionService::class,
		DependantRetentionIndexInterface::class        => DependantRetentionIndex::class,
		RetentionEvaluatorInterface::class             => RetentionEvaluator::class,
		ContentReportServiceInterface::class           => ContentReportService::class,
		DsarServiceInterface::class                    => DsarService::class,
		EdepotTransferServiceInterface::class          => EdepotTransferService::class,
		EdepotTransferAttemptInterface::class          => EdepotTransferService::class,
		DataSubjectRequestServiceInterface::class      => DataSubjectRequestService::class,
		ErasurePreviewServiceInterface::class          => ErasurePreviewService::class,
		ErasurePreviewStoreInterface::class            => ErasurePreviewStore::class,
		ErasureRunnerInterface::class                  => ErasureRunner::class,
		CorrectionServiceInterface::class              => CorrectionService::class,
		DestructionListRepositoryInterface::class      => DestructionListRepository::class,
		DestructionReviewServiceInterface::class       => DestructionReviewService::class,
		DestructionReviewReminderInterface::class      => DestructionReviewService::class,
		LegalHoldServiceInterface::class               => LegalHoldService::class,
		ReviewOutcomeServiceInterface::class           => ReviewOutcomeService::class,
		ObjectHistoryForReadersInterface::class        => ObjectHistoryForReaders::class,
		ReadableAuditTrailListerInterface::class       => ReadableAuditTrailLister::class,
		PurposeGuardInterface::class                   => PurposeGuard::class,
		PurposeRegistryInterface::class                => PurposeRegistry::class,
		OpenConnectorTransportInterface::class         => OpenConnectorTransport::class,
		RestApiTransportInterface::class               => RestApiTransport::class,
		SelectielijstImportServiceInterface::class     => SelectielijstImportService::class,
		SelectielijstReferenceResolverInterface::class => SelectielijstReferenceResolver::class,
		TransferListServiceInterface::class            => TransferListService::class,
		TransferRecordServiceInterface::class          => TransferRecordService::class,
		TransferListRunInterface::class                => TransferListService::class,
		TransferRecordStoreInterface::class            => TransferRecordService::class,
		SftpTransportInterface::class                  => SftpTransport::class,
		SubjectExportServiceInterface::class           => SubjectExportService::class,
		CaseAccessControlInterface::class              => CaseAccessControl::class,
		CaseObjectAccessorInterface::class             => CaseObjectAccessor::class,
		DsarPolicyPackResolverInterface::class         => DsarPolicyPackResolver::class,
		EvidenceHarvestServiceInterface::class         => EvidenceHarvestService::class,
		ExportBundleServiceInterface::class            => ExportBundleService::class,
		IdentityVerifyRegistryInterface::class         => IdentityVerifyRegistry::class,
		RedactionWriteServiceInterface::class          => RedactionWriteService::class,
		RegulatorEscalateRegistryInterface::class      => RegulatorEscalateRegistry::class,
		SubjectExportAssemblerInterface::class         => SubjectExportService::class,
		ArchivalAnnotationValidatorInterface::class    => ArchivalAnnotationValidator::class,
		ElementMappingValidatorInterface::class        => ElementMappingValidator::class,
		ElevationServiceInterface::class               => ElevationService::class,
		AllowedOriginsInterface::class                 => HardeningPolicy::class,
		HardeningFloorsInterface::class                => HardeningPolicy::class,
		HardeningReportServiceInterface::class         => HardeningReportService::class,
		HardeningSettingsServiceInterface::class       => HardeningSettingsService::class,
		StatementServiceInterface::class               => StatementService::class,
		AuditAggregationServiceInterface::class        => AuditAggregationService::class,
		OrganisationContextAttributionInterface::class => OrganisationContextAttribution::class,
		PurposeAttributionInterface::class             => PurposeAttribution::class,
		TokenAttributionInterface::class               => TokenAttribution::class,
		AuditRetentionResolverInterface::class         => AuditRetentionResolver::class,
		SearchTrailServiceInterface::class             => SearchTrailService::class,
		RetentionServiceInterface::class               => RetentionService::class,
		ArchiveReferenceReaderInterface::class         => ArchiveActionDateCalculator::class,
		AuditSealInterface::class                      => AuditHashService::class,
		AuditRechainInterface::class                   => AuditHashService::class,
		DpiaPatternDetectorInterface::class            => DpiaPatternDetectionService::class,
		RetentionSweepInterface::class                 => RetentionSweepService::class,
	];
}//end class
