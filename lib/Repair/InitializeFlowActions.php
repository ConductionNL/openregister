<?php

/**
 * Seed OpenRegister's own action-authorization matrix (ADR-023).
 *
 * OpenRegister ships `GenericActionAuthService` for the leaf apps it hosts but
 * never used it for itself, so its own write operations carried no per-action
 * right. Creating, editing and running a flow was open to every member of an
 * organisation, gated only by organisation scoping.
 *
 * The seed grants those four actions to `@authenticated`, which is exactly the
 * behaviour that already exists. Nothing changes on upgrade; what changes is
 * that the rights are now VISIBLE in the matrix and an admin can narrow them.
 *
 * Idempotent by the generic step's own rule: a matrix that already has entries
 * is preserved, so an admin's tightening is never undone by an upgrade.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Seeds the `openregister` action matrix from lib/actions.seed.json.
 */
class InitializeFlowActions extends GenericInitializeActions
{
    /**
     * Constructor.
     *
     * @param OpenRegisterActionAuthService $actionAuth The action-auth service.
     * @param IAppManager                   $appManager Resolves the app path for the seed file.
     * @param LoggerInterface               $logger     PSR logger.
     */
    public function __construct(
        OpenRegisterActionAuthService $actionAuth,
        IAppManager $appManager,
        LoggerInterface $logger
    ) {
        parent::__construct(
            appId: 'openregister',
            actionAuth: $actionAuth,
            appManager: $appManager,
            logger: $logger
        );

    }//end __construct()
}//end class
