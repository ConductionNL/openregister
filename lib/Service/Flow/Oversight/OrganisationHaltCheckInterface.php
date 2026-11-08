<?php

/**
 * OrganisationHaltCheckInterface: the organisation-halt oversight check as its registration listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Oversight
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

namespace OCA\OpenRegister\Service\Flow\Oversight;

use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;

/**
 * The organisation-halt check, typed for callers outside the Service component.
 *
 * Its only outside caller hands it to {@see \OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent::registerCheck()},
 * which takes an IFlowOversightCheck, so the contract adds nothing to it. Binding it in
 * ContractBindings lets that caller resolve the check without naming the class.
 */
interface OrganisationHaltCheckInterface extends IFlowOversightCheck {
}//end interface
