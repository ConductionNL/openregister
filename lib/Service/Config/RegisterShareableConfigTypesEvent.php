<?php

/**
 * The event apps listen on to contribute shareable configuration types.
 *
 * Mirrors RegisterFlowNodesEvent: the registry dispatches this once, lazily, and
 * every app that has a listener adds its type(s) to the passed registry. An app
 * contributing a shareable config type looks exactly like one contributing a
 * flow node — one registration idiom for the whole app.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCP\EventDispatcher\Event;

/**
 * Collects the shareable configuration types every app contributes.
 */
class RegisterShareableConfigTypesEvent extends Event
{
    /**
     * Constructor.
     *
     * @param ShareableConfigTypeRegistry $registry The registry to contribute to.
     */
    public function __construct(private readonly ShareableConfigTypeRegistry $registry)
    {

    }//end __construct()

    /**
     * Contribute a shareable configuration type.
     *
     * @param IShareableConfigType $type The type.
     *
     * @return void
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function registerType(IShareableConfigType $type): void
    {
        $this->registry->register(type: $type);

    }//end registerType()
}//end class
