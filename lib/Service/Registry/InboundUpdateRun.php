<?php

/**
 * OpenRegister InboundUpdateRun
 *
 * Progress of applying one inbound update to its matching subscriptions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registry;

/**
 * The update being applied and what came of it per subscription so far.
 * Built and read by RegistrySubscriptionService only.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */
final class InboundUpdateRun {

	/**
	 * Object uuids the update was written to.
	 *
	 * @var array<int, string>
	 */
	public array $applied = [];

	/**
	 * Per refused object, the supplied properties it was refused for.
	 *
	 * @var array<int, array{objectUuid: string, properties: array<int, string>}>
	 */
	public array $rejected = [];

	/**
	 * Start a run.
	 *
	 * @param InboundUpdate $update The update being applied.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function __construct(
		public readonly InboundUpdate $update,
	) {
	}//end __construct()

	/**
	 * Record that one object's update was refused.
	 *
	 * @param string             $objectUuid The refused object's uuid.
	 * @param array<int, string> $properties The properties it was refused for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function reject(string $objectUuid, array $properties): void {
		$this->rejected[] = ['objectUuid' => $objectUuid, 'properties' => $properties];
	}//end reject()
}//end class
