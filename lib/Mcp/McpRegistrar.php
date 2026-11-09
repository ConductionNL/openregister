<?php

/**
 * McpRegistrar: wiring for the Mcp component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
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

namespace OCA\OpenRegister\Mcp;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body and the binding map live in McpWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Binds the Mcp component's interfaces and registers its McpToolsService factory.
 */
final class McpRegistrar
{

    /**
     * Interface => the class behind it.
     */
    public const BINDINGS = McpWiring::BINDINGS;

    /**
     * Register the Mcp component's bindings.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec exclude Container wiring; no behaviour of its own.
     */
    public static function register(IRegistrationContext $context): void
    {
        McpWiring::register(context: $context);
    }//end register()
}//end class
