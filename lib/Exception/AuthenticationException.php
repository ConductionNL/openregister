<?php

/**
 * Authentication Exception.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
=======
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception for storing authentication failures with structured details.
 *
 * @package OCA\OpenRegister\Exception
 */
class AuthenticationException extends Exception
{

    /**
     * Details describing why authentication failed.
     *
     * @var array
     */
    private array $details;

    /**
     * Create a new AuthenticationException.
     *
     * @param string $message A human-readable error message
     * @param array  $details Structured details about the failure
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-26
=======
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-26
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function __construct(string $message, array $details)
    {
        $this->details = $details;
        parent::__construct(message: $message);

    }//end __construct()

    /**
     * Get the failure details.
     *
     * @return array The details array.
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-26
=======
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-26
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getDetails(): array
    {
        return $this->details;

    }//end getDetails()
}//end class
