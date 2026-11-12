<?php

/**
 * FlowDryRunServiceInterface: what callers outside the component use of FlowDryRunService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Declares only what callers outside the component use of FlowDryRunService; the rest
 * of the class stays its own.
 */
interface FlowDryRunServiceInterface {

	/**
	 * The steps a flow would take for an object, and what each would do.
	 *
	 * @param string $flowId   The flow uuid.
	 * @param string $register The object's register.
	 * @param string $schema   The object's schema.
	 * @param string $objectId The object's uuid.
	 *
	 * @return array{status: string, error?: string, steps: array<int, array<string, mixed>>}
	 *
	 * @throws DoesNotExistException When the flow or the object is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function dryRun(string $flowId, string $register, string $schema, string $objectId): array;

	/**
	 * The steps a flow would take for an example payload, and what each would do.
	 *
	 * The payload stands in for the object: it is the data of an object that
	 * does not exist, so nothing is read and there is nothing to authorise
	 * beyond the flow itself.
	 *
	 * @param string              $flowId  The flow uuid.
	 * @param array<string,mixed> $payload The example object data.
	 *
	 * @return array{status: string, error?: string, steps: array<int, array<string, mixed>>}
	 *
	 * @throws DoesNotExistException When the flow is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function dryRunPayload(string $flowId, array $payload): array;
}//end interface
