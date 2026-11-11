<?php

/**
 * The contract bindings of flows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Bindings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Bindings;

/**
 * Contract => service map for flows.
 *
 * ContractBindings::BINDINGS spreads this map, so an entry here is registered
 * as an alias exactly like an entry written in ContractBindings itself. It
 * exists so that lanes adding bindings for different areas edit different
 * files.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FlowBindings {

	/**
	 * Contract => the service that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		\OCA\OpenRegister\Service\Flow\FlowRunSignalInterface::class => \OCA\OpenRegister\Service\Flow\FlowRunSignalService::class,
		\OCA\OpenRegister\Service\Flow\Nodes\LockObjectNodeInterface::class => \OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode::class,
		\OCA\OpenRegister\Service\Flow\Principal\PrincipalKindsInterface::class => \OCA\OpenRegister\Service\Flow\Principal\PrincipalKinds::class,
		\OCA\OpenRegister\Service\Flow\Timer\CalendarRecomputeInterface::class => \OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute::class,
		\OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface::class => \OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic::class,
		\OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface::class => \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendars::class,
		\OCA\OpenRegister\Service\Task\TaskCalendarDocumentInterface::class => \OCA\OpenRegister\Service\Task\TaskCalendarDocument::class,
	];
}//end class
