<?php

/**
 * Marks a node that has no effect outside the items it returns.
 *
 * A flow dry-run performs such a step for real, so the items a later
 * condition reads are shaped exactly as in a run, and describes every other
 * step instead of performing it. Declaring it is a promise: the node writes no
 * object, file, run, audit entry or setting, sends nothing and calls nothing
 * outside the process. A node that only reads may declare it.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A node a dry-run may perform.
 */
interface IFlowSideEffectFree {
}//end interface
