<?php

/**
 * RemoteRecipientDenialInterface: the deny rules applied to a federated recipient.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * The port through which federation code asks the permission evaluator
 * whether a deny rule removes a verb from a remote recipient. Bound to the
 * same evaluator as PermissionExplanationInterface, which extends it.
 */
interface RemoteRecipientDenialInterface {

	/**
	 * The denial that removes a verb from a federated recipient on one object, or null.
	 *
	 * @param SchemaInterface       $schema     The object's schema.
	 * @param ObjectEntityInterface $object     The object.
	 * @param array<int, string>    $principals The recipient's principal names (`remote:*`, `remote:<cloud id>`).
	 * @param string                $action     The verb.
	 *
	 * @return array|null The denial, or null when the verb is not denied; an object the
	 *                    evaluator cannot read answers as an unresolvable denial.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	public function remoteRecipientDenial(SchemaInterface $schema, ObjectEntityInterface $object, array $principals, string $action): ?array;
}//end interface
