<?php

/**
 * OpenRegister Object Graph Transfer Refusal
 *
 * Why a transfer of an object graph between organisations was refused, with
 * the HTTP status the refusal answers with.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use RuntimeException;

/**
 * A refused transfer: the message says why, the code is the HTTP status (400, 403, 404 or 409).
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 */
class ObjectGraphTransferRefusal extends RuntimeException {
}//end class
