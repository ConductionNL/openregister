<?php

/**
 * C12 cases: declared object actions, read from the schema save checks and the object-actions endpoint.
 *
 * The callers type against ObjectActionDefinitionInterface and
 * ObjectActionHistoryInterface, and make the static reads and the selection
 * construction through ObjectActionDeclarations. MacroActionBinding,
 * ObjectActionSelection and FlowNextHint have no interface: callers outside
 * lib/Service no longer need their type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Flow;
use OCA\OpenRegister\Service\ObjectAction;

return [
	'classes' => [
		Flow\FlowNextHint::class => null,
		Flow\MacroActionBinding::class => null,
		ObjectAction\ObjectActionDefinition::class => ObjectAction\ObjectActionDefinitionInterface::class,
		ObjectAction\ObjectActionHistory::class => ObjectAction\ObjectActionHistoryInterface::class,
		ObjectAction\ObjectActionSelection::class => null,
	],
	'kept' => [],
];
