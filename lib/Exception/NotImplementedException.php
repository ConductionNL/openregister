<?php

/**
 * NotImplementedException — provider lacks a CRUD operation.
 *
 * Thrown by IntegrationProvider implementations whose storage
 * strategy doesn't support a particular operation. Query-time
 * providers (NC Activity, NC Shares) throw on create/update/delete;
 * list-only providers may also throw on get().
 *
 * The ObjectsController catches this and translates it to HTTP 501
 * Not Implemented (AD-22).
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Provider lacks a CRUD operation for its storage strategy.
 */
class NotImplementedException extends \RuntimeException
{

}//end class
