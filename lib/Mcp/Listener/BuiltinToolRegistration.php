<?php

/**
 * Registers OpenRegister's five built-in tools into a ToolRegistrationEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Mcp\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;

/**
 * The built-in half of {@see ToolRegistrationListener}: the register, schema,
 * objects, application and agent tools, each under its fixed registry id.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class BuiltinToolRegistration {

	/**
	 * Constructor
	 *
	 * @param BuiltinDataTools     $dataTools     The register, schema and objects tools.
	 * @param BuiltinPlatformTools $platformTools The application and agent tools.
	 */
	public function __construct(
		private readonly BuiltinDataTools $dataTools,
		private readonly BuiltinPlatformTools $platformTools,
	) {
	}//end __construct()

	/**
	 * Register the five built-in OpenRegister tools into the ToolRegistrationEvent.
	 *
	 * @param ToolRegistrationEvent $event The tool registration event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function registerInto(ToolRegistrationEvent $event): void {
		$tools = [
			'openregister.register'    => [$this->dataTools->registerTool, 'icon-category-office'],
			'openregister.schema'      => [$this->dataTools->schemaTool, 'icon-category-customization'],
			'openregister.objects'     => [$this->dataTools->objectsTool, 'icon-category-organization'],
			'openregister.application' => [$this->platformTools->applicationTool, 'icon-category-integration'],
			'openregister.agent'       => [$this->platformTools->agentTool, 'icon-category-monitoring'],
		];

		foreach ($tools as $id => [$tool, $icon]) {
			$event->registerTool(
				id: $id,
				tool: $tool,
				metadata: [
					'name' => $tool->getName(),
					'description' => $tool->getDescription(),
					'icon' => $icon,
					'app' => 'openregister',
				]
			);
		}
	}//end registerInto()
}//end class
