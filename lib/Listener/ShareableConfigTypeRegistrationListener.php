<?php

/**
 * Registers OpenRegister's own built-in shareable configuration types.
 *
 * OpenRegister contributes its types (Flows, and later registers/schemas) through
 * the same event every consuming app uses, so the contribution path is exercised
 * by its owner and cannot rot unnoticed — the same reasoning as the flow-node and
 * flow-resolver registration listeners.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent;
use OCA\OpenRegister\Service\Config\SchemaShareableConfigScanner;
use OCA\OpenRegister\Service\Config\Types\ConfigSetShareableConfigType;
use OCA\OpenRegister\Service\Config\Types\FlowShareableConfigType;
use OCA\OpenRegister\Service\Config\Types\RegisterSchemaShareableConfigType;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contributes the built-in shareable configuration types.
 *
 * @template-implements IEventListener<RegisterShareableConfigTypesEvent>
 */
class ShareableConfigTypeRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param FlowShareableConfigType $flows The built-in "Flows" type.
	 * @param RegisterSchemaShareableConfigType $registers The built-in "Registers & schemas" type.
	 * @param ConfigSetShareableConfigType $configSet The built-in "Configuration set" type.
	 * @param SchemaShareableConfigScanner $scanner Turns shareable-marked schemas into types.
	 */
	public function __construct(
		private readonly FlowShareableConfigType $flows,
		private readonly RegisterSchemaShareableConfigType $registers,
		private readonly ConfigSetShareableConfigType $configSet,
		private readonly SchemaShareableConfigScanner $scanner,
	) {

	}//end __construct()

	/**
	 * Register the built-in types plus every marked schema's type.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterShareableConfigTypesEvent) === false) {
			return;
		}

		$event->registerType(type: $this->flows);
		$event->registerType(type: $this->registers);
		$event->registerType(type: $this->configSet);

		// Any schema that marked itself shareable becomes a type — no per-app code.
		foreach ($this->scanner->scan() as $type) {
			$event->registerType(type: $type);
		}

	}//end handle()
}//end class
