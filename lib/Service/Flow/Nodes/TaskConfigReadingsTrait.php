<?php

/**
 * The config readings UserTaskConfig and PortalTaskConfig answer alike.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;

/**
 * The outcome key, title and heartbeat of a task step, read through the host's `$this->common` (a TaskConfigCommon).
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
trait TaskConfigReadingsTrait {
	/**
	 * The item key the outcome is written under.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
	 */
	public function outcomeKey(array $config): string {
		return $this->common->outcomeKey(config: $config);
	}//end outcomeKey()

	/**
	 * The title, templated against the representative item.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array                $items  The input items.
	 *
	 * @return string The rendered title.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function renderedTitle(array $config, array $items): string {
		return $this->common->renderedTitle(config: $config, items: $items);
	}//end renderedTitle()

	/**
	 * When to wake up and re-check, absent a wake. Never null.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return DateTime The next heartbeat.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	public function heartbeatAt(array $config): DateTime {
		return $this->common->heartbeatAt(config: $config);
	}//end heartbeatAt()
}//end trait
