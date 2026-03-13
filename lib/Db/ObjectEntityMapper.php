<?php

/**
 * Backward-compatibility alias for ObjectEntityMapper.
 *
 * The original ObjectEntityMapper (blob-storage mapper) has been removed.
 * All object storage now goes through UnifiedObjectMapper → MagicMapper.
 * This file ensures existing type hints and DI references continue to work.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Backward-compatibility alias for UnifiedObjectMapper.
 *
 * @package OCA\OpenRegister\Db
 *
 * @deprecated Use UnifiedObjectMapper directly in new code.
 */
class ObjectEntityMapper extends UnifiedObjectMapper
{
}//end class
