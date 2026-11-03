<?php

/**
 * Lets other apps contribute Twig functions to the one mapping engine.
 *
 * Mapping evaluation lives in OpenRegister, but some mapping functions can only
 * be provided by the app that owns the capability: `callSource` needs
 * OpenConnector's CallService, the synchronisation-contract lookups need its
 * contract service. Importing those into OpenRegister would invert the fleet's
 * dependency direction — OpenRegister is the foundation and must load on an
 * instance where OpenConnector is absent.
 *
 * So the engine stays here and the app-specific functions are contributed, the
 * same shape as `RegisterFlowNodesEvent` for flow nodes.
 *
 * A contributed function MUST also be allowlisted, because the mapping Twig
 * environment is SANDBOXED (SSTI hardening — mapping templates are user-authored
 * and compiled at runtime). `registerFunction()` does both in one call so a
 * contributor cannot register a function that the sandbox then silently blocks:
 * that failure surfaces as "unknown function" inside a mapping, far from the
 * registration site.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\EventDispatcher\Event;
use Twig\TwigFunction;

/**
 * Collects Twig functions contributed by other apps.
 */
class RegisterMappingFunctionsEvent extends Event {

	/**
	 * The contributed functions.
	 *
	 * @var array<int, TwigFunction>
	 */
	private array $functions = [];

	/**
	 * The names those functions are allowed to be called by, for the sandbox.
	 *
	 * @var array<int, string>
	 */
	private array $allowedNames = [];

	/**
	 * Contribute one Twig function, and allowlist it for the sandbox.
	 *
	 * @param TwigFunction $function The function to expose to mapping templates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
	 */
	public function registerFunction(TwigFunction $function): void {
		$this->functions[] = $function;
		$this->allowedNames[] = $function->getName();

	}//end registerFunction()

	/**
	 * Every contributed function.
	 *
	 * @return array<int, TwigFunction> The functions.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
	 */
	public function getFunctions(): array {
		return $this->functions;
	}//end getFunctions()

	/**
	 * The names to add to the sandbox allowlist.
	 *
	 * @return array<int, string> The allowed function names.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
	 */
	public function getAllowedNames(): array {
		return $this->allowedNames;
	}//end getAllowedNames()
}//end class
