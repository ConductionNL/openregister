<?php

/**
 * RestApiTransportInterface: the RestApiTransport as a transport the container tells apart from the other transports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

/**
 * RestApiTransport as one transport among several.
 *
 * The e-Depot settings controller holds each transport separately, so each needs
 * its own type for the container to resolve; the methods are TransportInterface's.
 * Callers depend on this interface, not on the class.
 */
interface RestApiTransportInterface extends TransportInterface {
}//end interface
