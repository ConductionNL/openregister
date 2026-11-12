<?php

/**
 * OpenRegister BuiltinLifecycleActionsInterface
 *
 * The lifecycle actions OpenRegister ships itself, published as one map so
 * resolvers outside lib/Lifecycle name this contract instead of the concrete
 * handler classes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Lifecycle
 * @package  OCA\OpenRegister\Lifecycle
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

namespace OCA\OpenRegister\Lifecycle;

use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;

/**
 * Built-in action-name → handler-FQCN map.
 *
 * The handlers are resolved through the OR container so their own
 * dependencies are autowired.
 */
interface BuiltinLifecycleActionsInterface {

	/**
	 * Built-in action-name → handler-FQCN map.
	 *
	 * @var array<string, class-string<LifecycleActionInterface>>
	 */
	public const BUILTINS = [
		'set-fields' => SetFieldsAction::class,
		'set-field' => SetFieldsAction::class,
	];
}//end interface
