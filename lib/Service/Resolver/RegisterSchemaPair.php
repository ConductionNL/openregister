<?php

/**
 * OpenRegister RegisterSchemaPair
 *
 * Value object holding a resolved Register + Schema pair.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resolver
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-resolver-service/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resolver;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Readonly value object holding a hydrated Register + Schema pair.
 *
 * Returned by RegisterResolverService::resolvePair() to bundle both
 * entities and their resolved slug/ID strings into a single immutable object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resolver
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class RegisterSchemaPair
{
    /**
     * Constructor.
     *
     * @param Register $register           The resolved Register entity.
     * @param Schema   $schema             The resolved Schema entity.
     * @param string   $resolvedRegisterId The slug/UUID string that identified the register.
     * @param string   $resolvedSchemaId   The slug/UUID string that identified the schema.
     *
     * @return void
     */
    public function __construct(
        private readonly Register $register,
        private readonly Schema $schema,
        private readonly string $resolvedRegisterId,
        private readonly string $resolvedSchemaId,
    ) {
    }//end __construct()

    /**
     * Get the hydrated Register entity.
     *
     * @return Register
     */
    public function getRegister(): Register
    {
        return $this->register;
    }//end getRegister()

    /**
     * Get the hydrated Schema entity.
     *
     * @return Schema
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }//end getSchema()

    /**
     * Get the resolved slug/UUID used to find the register.
     *
     * @return string
     */
    public function getResolvedRegisterId(): string
    {
        return $this->resolvedRegisterId;
    }//end getResolvedRegisterId()

    /**
     * Get the resolved slug/UUID used to find the schema.
     *
     * @return string
     */
    public function getResolvedSchemaId(): string
    {
        return $this->resolvedSchemaId;
    }//end getResolvedSchemaId()
}//end class
