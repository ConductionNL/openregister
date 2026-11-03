<?php

/**
 * The catalogue of shareable configuration types, filled by an event.
 *
 * Mirrors FlowNodeRegistry: types are collected lazily, once, by dispatching
 * RegisterShareableConfigTypesEvent the first time the catalogue is read — so
 * which app registered first does not depend on app load order, and the owner of
 * the mechanism (OpenRegister) uses the same event every consuming app does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
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

namespace OCA\OpenRegister\Service\Config;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Holds the registered shareable configuration types.
 */
class ShareableConfigTypeRegistry {

	/**
	 * Registered types, keyed by id.
	 *
	 * @var array<string, IShareableConfigType>
	 */
	private array $types = [];

	/**
	 * Whether the registration event has been dispatched yet.
	 *
	 * @var boolean
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches the registration event.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Add a type. A later registration of the same id wins (last write), which
	 * lets an instance override a built-in with its own.
	 *
	 * @param IShareableConfigType $type The type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function register(IShareableConfigType $type): void {
		$this->types[$type->getId()] = $type;

	}//end register()

	/**
	 * Every registered type.
	 *
	 * @return array<string, IShareableConfigType> The types, keyed by id.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function all(): array {
		$this->load();
		return $this->types;
	}//end all()

	/**
	 * One type by id, or null when nothing owns it.
	 *
	 * @param string $id The type id.
	 *
	 * @return IShareableConfigType|null The type, or null.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function get(string $id): ?IShareableConfigType {
		$this->load();
		return ($this->types[$id] ?? null);
	}//end get()

	/**
	 * Dispatch the registration event once, so every app contributes its types.
	 *
	 * @return void
	 */
	private function load(): void {
		if ($this->loaded === true) {
			return;
		}

		$this->loaded = true;
		try {
			$this->dispatcher->dispatchTyped(new RegisterShareableConfigTypesEvent(registry: $this));
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ShareableConfigTypeRegistry] Failed to collect types: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

	}//end load()
}//end class
