<?php

/**
 * C12 cases: declared object actions, read from the schema save checks and the selection bundle.
 *
 * DeclaredActionCheck and ReferenceLoopCheck make the static reads through
 * ObjectActionDeclarations and type a parsed action against
 * ObjectActionDefinitionInterface; ObjectActionsSelection builds the selection
 * there. MacroActionBinding and ObjectActionSelection have no interface:
 * callers outside lib/Service no longer need their type.
 *
 * ObjectActionsController is kept as a caller of ObjectActionDefinition: it
 * still types against the class, and that controller is changed by an open
 * pull request, so it is left for after that lands.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Flow;
use OCA\OpenRegister\Service\ObjectAction;

return [
	'classes' => [
		Flow\MacroActionBinding::class => null,
		ObjectAction\ObjectActionDefinition::class => ObjectAction\ObjectActionDefinitionInterface::class,
		ObjectAction\ObjectActionSelection::class => null,
	],
	'kept' => [
		ObjectAction\ObjectActionDefinition::class => ['Controller/ObjectActionsController.php'],
	],
];
