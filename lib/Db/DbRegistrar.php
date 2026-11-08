<?php

/**
 * DbRegistrar: container registrations of the Db component.
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

use OCA\OpenRegister\Db\MagicMapper\MagicAccessPredicateInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDriftInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableReaderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectDeletionInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectLockInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectWriterInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicSlugStoreInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandlerInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableLocatorInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableMaintenanceInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the interfaces other components type against to the Db classes
 * that implement them.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class DbRegistrar {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		ApplicationMapperInterface::class => ApplicationMapper::class,
		AuditTrailMapperInterface::class => AuditTrailMapper::class,
		BulkJobMapperInterface::class => BulkJobMapper::class,
		BulkJobMemberMapperInterface::class => BulkJobMemberMapper::class,
		EndpointLogMapperInterface::class => EndpointLogMapper::class,
		EndpointMapperInterface::class => EndpointMapper::class,
		EntityRelationMapperInterface::class => EntityRelationMapper::class,
		FlowRunMapperInterface::class => FlowRunMapper::class,
		FlowStateMapperInterface::class => FlowStateMapper::class,
		FlowTriggerMapperInterface::class => FlowTriggerMapper::class,
		JobRunMapperInterface::class => JobRunMapper::class,
		MagicAccessPredicateInterface::class => MagicMapper::class,
		MagicColumnDriftInterface::class => MagicMapper::class,
		MagicCrossTableReaderInterface::class => MagicMapper::class,
		MagicMapperInterface::class => MagicMapper::class,
		MagicObjectDeletionInterface::class => MagicMapper::class,
		MagicObjectFinderInterface::class => MagicMapper::class,
		MagicObjectLockInterface::class => MagicMapper::class,
		MagicObjectSearchInterface::class => MagicMapper::class,
		MagicObjectWriterInterface::class => MagicMapper::class,
		MagicRelationReaderInterface::class => MagicMapper::class,
		MagicSlugStoreInterface::class => MagicMapper::class,
		MagicStatisticsInterface::class => MagicMapper::class,
		MagicTableHandlerInterface::class => MagicTableHandler::class,
		MagicTableLocatorInterface::class => MagicMapper::class,
		MagicTableMaintenanceInterface::class => MagicMapper::class,
		MagicTableRowReaderInterface::class => MagicMapper::class,
		MappingMapperInterface::class => MappingMapper::class,
		ObjectRelationMapperInterface::class => ObjectRelationMapper::class,
		OrganisationMapperInterface::class => OrganisationMapper::class,
		RegisterMapperInterface::class => RegisterMapper::class,
		RuleRunSummaryMapperInterface::class => RuleRunSummaryMapper::class,
		SchemaMapperInterface::class => SchemaMapper::class,
		SourceMapperInterface::class => SourceMapper::class,
		SyncRecordMapperInterface::class => SyncRecordMapper::class,
		TranslationMapperInterface::class => TranslationMapper::class,
		WebhookLogMapperInterface::class => WebhookLogMapper::class,
		WebhookMapperInterface::class => WebhookMapper::class,
	];

	/**
	 * Register every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $class) {
			$context->registerServiceAlias($contract, $class);
		}
	}//end register()
}//end class
