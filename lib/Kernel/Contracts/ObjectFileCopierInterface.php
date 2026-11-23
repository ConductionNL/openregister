<?php

/**
 * ObjectFileCopierInterface: copy an object's attached files onto another object.
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
 * Copies every file attached to one object onto another, one outcome per file. Implemented by the Files
 * component (Service/Object/CopyFiles), so the copy endpoint does not reach into Files directly.
 */
interface ObjectFileCopierInterface {

	/**
	 * Copy every file attached to the source onto the copy.
	 *
	 * @param ObjectEntityInterface $source The object copied.
	 * @param ObjectEntityInterface $copy   The new object.
	 *
	 * @return list<array{id: string, title: string, outcome: string, reason: string|null}> One outcome per file, in the source's order.
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public function copy(ObjectEntityInterface $source, ObjectEntityInterface $copy): array;
}//end interface
