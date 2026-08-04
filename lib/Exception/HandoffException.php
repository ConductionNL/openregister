<?php

/**
 * HandoffException — typed, machine-readable handoff engine failures.
 *
 * One exception class with an error-code discriminator covering the
 * client-addressable failure modes of the semantic-object-handoff engine
 * (ADR-051): a handoff id the source schema does not declare
 * (`handoff-not-declared`, 404-class), no installed provider for the target
 * kind in hide mode (`handoff-provider-unavailable`, 409-class — explicitly
 * never a 5xx), and an incomplete provider binding
 * (`handoff-contract-incomplete`). The controller maps the code to the HTTP
 * status; API consumers switch on the `error` code, not the message.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Typed handoff-engine failure with a machine-readable error code.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: Graceful degradation when no provider implements the kind)
 */
class HandoffException extends \RuntimeException
{

    /**
     * The source schema declares no handoff with the requested id (404-class).
     *
     * @var string
     */
    public const NOT_DECLARED = 'handoff-not-declared';

    /**
     * No installed schema provides the target kind (hide mode; 409-class,
     * never a 5xx).
     *
     * @var string
     */
    public const PROVIDER_UNAVAILABLE = 'handoff-provider-unavailable';

    /**
     * Machine-readable error code (one of the class constants).
     *
     * @var string
     */
    private string $errorCode;

    /**
     * Constructor.
     *
     * @param string          $errorCode One of the class constants.
     * @param string          $message   Human-readable explanation.
     * @param \Throwable|null $previous  Optional wrapped throwable.
     *
     * @return void
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, hide mode)
     */
    public function __construct(string $errorCode, string $message, ?\Throwable $previous=null)
    {
        parent::__construct(message: $message, code: 0, previous: $previous);
        $this->errorCode = $errorCode;

    }//end __construct()

    /**
     * The machine-readable error code.
     *
     * @return string One of the class constants.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, hide mode)
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;

    }//end getErrorCode()
}//end class
