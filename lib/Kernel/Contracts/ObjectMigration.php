<?php

/**
 * ObjectMigration: one move of objects from one register and schema to another.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;


/**
 * Which objects move, from where to where, and how their properties map.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectMigration
{
    /**
     * Constructor
     *
     * @param RegisterSchemaRef $source    The register and schema the objects come from.
     * @param RegisterSchemaRef $target    The register and schema the objects move to.
     * @param array             $objectIds The requested object ids.
     * @param array             $mapping   Target property => source property.
     */
    public function __construct(
        public readonly RegisterSchemaRef $source,
        public readonly RegisterSchemaRef $target,
        public readonly array $objectIds,
        public readonly array $mapping
    ) {
    }//end __construct()
}//end class
