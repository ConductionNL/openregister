<?php

/**
 * C12 cases: the flow dry-run, timer-diagnostic, working-calendar and published-graph services.
 *
 * The flow and calendar endpoints type against the interfaces and build a
 * calendar through WorkingCalendars; the container resolves each injected
 * interface to the same shared instance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Flow;
use OCA\OpenRegister\Service\Flow\Timer;

return [
	'classes' => [
		Flow\FlowDryRunService::class => Flow\FlowDryRunServiceInterface::class,
		Flow\FlowPublishedGraph::class => Flow\FlowPublishedGraphInterface::class,
		Timer\TermDiagnostic::class => Timer\TermDiagnosticInterface::class,
		Timer\WorkingCalendar::class => Timer\WorkingCalendarInterface::class,
	],
	'kept' => [],
];
