<?php

/**
 * The reference-provider side of ObjectPreviewFormatter.
 *
 * Reference providers outside lib/Service (lib/Reference, lib/AppHost/Reference) type
 * against this interface; ContractBindings aliases it to ObjectPreviewFormatter, so
 * they share the one formatter instance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Reference;

use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectPreviewInterface;

/**
 * The reference-provider side of ObjectPreviewFormatter.
 *
 * Its methods are those of the Integrations port ObjectPreviewInterface, which
 * the schema-scoped reference providers type against.
 */
interface ObjectPreviewFormatterInterface extends ObjectPreviewInterface {
}//end interface
