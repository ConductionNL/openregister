<?php

/**
 * The escape hatch from the dispatcher's acting-identity scoping.
 *
 * The dispatcher executes every CONTRIBUTED node inside the run's `runAs`
 * identity ({@see FlowRunAsScope}), so an app node's reads and writes inherit
 * the run's rights without the app building a wrapper. A node that declares
 * THIS interface is executed bare instead — under whatever session is ambient,
 * which under the cron worker is nobody.
 *
 * DECLARING IT IS TAKING ON OBLIGATIONS, and they are worth reading twice:
 *
 * - the node manages its own acting identity for every read and write it
 *   performs, the way the engine's own nodes do — validating that the
 *   identity exists and is enabled before acting as it;
 * - a node whose work is genuinely system-level (installation plumbing,
 *   shipped-data seeding) still may NOT reach `ObjectService::runAsSystem()`
 *   from node code — ADR-099 forbids the userless principal to flow nodes,
 *   and `SystemOperationContextBoundaryTest` pins the call-site set;
 * - an unscoped write that "works" under an interactive session and is
 *   refused under the worker is exactly the defect the default wrap removes.
 *   Opting out re-opens it, for this node, deliberately.
 *
 * The honest use is a node that performs NO register work at all, or one
 * whose scoping needs differ per call in a way the run-level identity cannot
 * express — and such a node documents its reasoning where it declares this.
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
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-contributed-node-executes-under-the-runs-acting-identity
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Marks a contributed node that manages its own acting identity.
 */
interface IFlowSelfScopedNode {

}//end interface
