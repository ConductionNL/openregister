<?php

/**
 * A node that can say what it would do, without doing it.
 *
 * A flow dry-run asks a step that has side effects for its preview instead
 * of performing it. The answer is computed from the items and the step's
 * configuration only: it reads what it needs and writes and sends nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Describes what a step would do.
 */
interface IFlowNodePreview {
	/**
	 * What this step would do with these items, without doing it.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array Any of `summary` (one sentence), `changes` (`[{field, value}]`)
	 *               and `messages` (`[{channel, to, subject, body}]`).
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
	 */
	public function preview(array $items, array $config, array $context): array;
}//end interface
