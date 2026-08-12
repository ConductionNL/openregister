<?php

/**
 * Dispatched so apps can contribute oversight checks.
 *
 * The mirror of `RegisterFlowNodesEvent`: the engine discovers what may stop a
 * run the same way it discovers what a run can do. Keeping both on the same
 * idiom is what stops app-specific safety logic being written into the engine,
 * which is how hermiq's kill switch and budget ended up applying to one node
 * type in one app.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Carries the registry an app registers its oversight checks on.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class RegisterFlowOversightEvent extends Event
{
    /**
     * Constructor.
     *
     * @param FlowOversightRegistry $registry The registry to contribute to.
     */
    public function __construct(private readonly FlowOversightRegistry $registry)
    {
        parent::__construct();

    }//end __construct()

    /**
     * Contribute an oversight check.
     *
     * @param IFlowOversightCheck $check The check.
     *
     * @return void
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function registerCheck(IFlowOversightCheck $check): void
    {
        $this->registry->register(check: $check);

    }//end registerCheck()
}//end class
