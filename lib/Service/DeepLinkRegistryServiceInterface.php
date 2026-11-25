<?php

/**
 * DeepLinkRegistryServiceInterface: what callers outside the Service component use of DeepLinkRegistryService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Event\DeepLinkCollectorInterface;

/**
 * The deep-link registration and resolution other components call on DeepLinkRegistryService.
 *
 * Adding a registration is the Kernel-area DeepLinkCollectorInterface, which the
 * registration event hands its listeners.
 */
interface DeepLinkRegistryServiceInterface extends DeepLinkResolverInterface, DeepLinkCollectorInterface {

	/**
	 * Ask the apps for their deep links, once per process.
	 *
	 * The first read of an empty registry (resolve(), registrations(),
	 * hasRegistrations()) calls this, so the apps are asked only in a process
	 * that needs a deep link, in a request and a background job alike.
	 *
	 * @param IEventDispatcher|null $dispatcher The dispatcher, or null to take it from the container.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/deep-link-registry/spec.md#requirement-the-registry-must-fill-itself-in-a-process-that-never-booted-openregister
	 */
	public function requestRegistrations(?IEventDispatcher $dispatcher = null): void;

	/**
	 * Every registration, one per claimed (register, schema) combination.
	 *
	 * @return array<int,DeepLinkRegistration> The registrations, in the order they were added.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.1
	 */
	public function registrations(): array;

	/**
	 * The app that claimed a register and schema.
	 *
	 * @param int $registerId The register's id.
	 * @param int $schemaId   The schema's id.
	 *
	 * @return string|null The claiming app's id, or null when no app claimed the pair.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.2
	 */
	public function resolveAppId(int $registerId, int $schemaId): ?string;

	/**
	 * The owning app's own route for a task about an object of this register and schema.
	 *
	 * @param int                  $registerId The subject's register id.
	 * @param int                  $schemaId   The subject's schema id.
	 * @param array<string, mixed> $taskData   The task row (uuid, objectUuid, registerId, schemaId).
	 *
	 * @return string|null The URL, or null when no app registered a task route for the pair.
	 *
	 * @spec openspec/changes/flow-task-search-provider/specs/unified-search-provider/spec.md#requirement-nextcloud-search-finds-the-flow-tasks-a-user-may-see
	 */
	public function resolveTaskUrl(int $registerId, int $schemaId, array $taskData): ?string;

}//end interface
