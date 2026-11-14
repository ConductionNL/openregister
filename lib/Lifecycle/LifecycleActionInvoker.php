<?php

/**
 * OpenRegister LifecycleActionInvoker
 *
 * Calls a lifecycle action handler in whichever form it implements.
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

use OCA\OpenRegister\Kernel\Compat\ImplementationForm;
use RuntimeException;

/**
 * Runs a lifecycle action handler written in either published form.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleActionInvoker {

	/**
	 * Constructor
	 *
	 * @param ImplementationForm $form Tells the two forms apart.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ImplementationForm $form=new ImplementationForm(),
	) {
	}//end __construct()

	/**
	 * Run the handler on a transitioning object.
	 *
	 * @param LifecycleActionInterface $action  The handler.
	 * @param LifecycleActionContext   $context The payloads, parameters and action name.
	 *
	 * @return array<string, mixed> The object payload the handler returned.
	 *
	 * @throws RuntimeException When the handler declares no execute().
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function execute(LifecycleActionInterface $action, LifecycleActionContext $context): array {
		if ($action instanceof ContextLifecycleActionInterface) {
			return $action->execute($context);
		}

		if ($action instanceof LegacyLifecycleActionInterface) {
			return $action->execute($context->objectData, $context->previousData, $context->parameters, $context->actionName);
		}

		if (method_exists($action, 'execute') === false) {
			throw new RuntimeException(sprintf('Lifecycle action %s declares no execute().', $action::class));
		}

		$execute = [$action, 'execute'];
		if ($this->form->takesObject(implementer: $action, method: 'execute', parameterClass: LifecycleActionContext::class) === true) {
			return $execute($context);
		}

		return $execute($context->objectData, $context->previousData, $context->parameters, $context->actionName);
	}//end execute()
}//end class
