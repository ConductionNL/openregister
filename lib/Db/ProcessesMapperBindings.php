<?php

/**
 * ProcessesMapperBindings: the map of the Processes area's mapper interfaces.
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

use OCA\OpenRegister\Kernel\Contracts\Compliance\AuditProcessContextInterface;

/**
 * Interface => mapper map of the Processes area, behind ProcessesMapperRegistrar.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ProcessesMapperBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AuditFlowAttributionInterface::class      => AuditFlowAttribution::class,
		AuditProcessContextInterface::class       => AuditProcessContext::class,
		BulkJobMapperInterface::class             => BulkJobMapper::class,
		BulkJobMemberMapperInterface::class       => BulkJobMemberMapper::class,
		CalendarFeedTokenMapperInterface::class   => CalendarFeedTokenMapper::class,
		CaseItemAuditMapperInterface::class       => CaseItemAuditMapper::class,
		CaseItemMapperInterface::class            => CaseItemMapper::class,
		CaseTokenMapperInterface::class           => CaseTokenMapper::class,
		DecisionMapperInterface::class            => DecisionMapper::class,
		FlowClaimMapperInterface::class           => FlowClaimMapper::class,
		FlowDefinitionMapperInterface::class      => FlowDefinitionMapper::class,
		FlowMapperInterface::class                => FlowMapper::class,
		FlowRunMapperInterface::class             => FlowRunMapper::class,
		FlowRunStepMapperInterface::class         => FlowRunStepMapper::class,
		FlowStateMapperInterface::class           => FlowStateMapper::class,
		FlowStreamMapperInterface::class          => FlowStreamMapper::class,
		FlowTimerEventMapperInterface::class      => FlowTimerEventMapper::class,
		FlowTimerFireMapperInterface::class       => FlowTimerFireMapper::class,
		FlowTimerMapperInterface::class           => FlowTimerMapper::class,
		FlowTriggerMapperInterface::class         => FlowTriggerMapper::class,
		FlowVersionMapperInterface::class         => FlowVersionMapper::class,
		FormLinkMapperInterface::class            => FormLinkMapper::class,
		HandoffQueueEntryMapperInterface::class   => HandoffQueueEntryMapper::class,
		RuleRunMapperInterface::class             => RuleRunMapper::class,
		RuleRunSummaryMapperInterface::class      => RuleRunSummaryMapper::class,
		RunObjectLockMapperInterface::class       => RunObjectLockMapper::class,
		SequenceMapperInterface::class            => SequenceMapper::class,
		TaskAuditMapperInterface::class           => TaskAuditMapper::class,
		TaskBlockerMapperInterface::class         => TaskBlockerMapper::class,
		TaskCandidateMapperInterface::class       => TaskCandidateMapper::class,
		TaskProjectionStateMapperInterface::class => TaskProjectionStateMapper::class,
		TaskRelationMapperInterface::class        => TaskRelationMapper::class,
		TaskSequenceMapperInterface::class        => TaskSequenceMapper::class,
	];
}//end class
