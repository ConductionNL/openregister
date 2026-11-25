<?php

/**
 * The built-in "Invite to a survey" flow step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The step a flow fires a survey invitation with.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
 */
interface SurveyInviteNodeInterface extends IFlowNode {
}//end interface
