<?php

/**
 * AgentPrincipalResolverInterface: the agent principal resolver as its registration listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
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

namespace OCA\OpenRegister\Service\Flow\Principal;

/**
 * The agent principal resolver, typed for callers outside the Service component.
 *
 * Its only outside caller hands it to {@see RegisterPrincipalResolversEvent::registerResolver()},
 * which takes an IPrincipalResolver, so the contract adds nothing to it. Binding it in
 * ContractBindings lets that caller resolve the resolver without naming the class.
 */
interface AgentPrincipalResolverInterface extends IPrincipalResolver {
}//end interface
