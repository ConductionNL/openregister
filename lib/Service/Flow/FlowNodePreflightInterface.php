<?php

/**
 * FlowNodePreflightInterface: what callers outside the Service component ask of FlowNodePreflight.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use UnexpectedValueException;

/**
 * The flow document preflight, as the flow controller, the preflight listener
 * and the MCP flow tools use it. Only the methods those callers call.
 */
interface FlowNodePreflightInterface {

	/**
	 * Whether a piece of object data is shaped like a flow document.
	 *
	 * @param array $data The object's data.
	 *
	 * @return boolean True when the data describes a flow graph.
	 */
	public function looksLikeFlow(array $data): bool;

	/**
	 * Check every step of a flow document against the live node registry.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 */
	public function inspect(array $flow): array;

	/**
	 * Refuse a document the instance cannot run, and log the rest.
	 *
	 * @param array       $flow  The flow document.
	 * @param string|null $label A human name for the flow, used in the message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a step type can never resolve here.
	 */
	public function assertRunnable(array $flow, ?string $label = null): void;

	/**
	 * Build the refusal message for a set of blocking findings.
	 *
	 * @param string                            $flow     The flow's name.
	 * @param array<int, array<string, string>> $blocking The blocking findings.
	 *
	 * @return string The message.
	 */
	public function describe(string $flow, array $blocking): string;
}//end interface
