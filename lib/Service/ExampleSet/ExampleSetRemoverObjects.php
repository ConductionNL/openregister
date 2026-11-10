<?php

/**
 * OpenRegister Example Set Remover Objects
 *
 * The collaborators the remover deletes a set's objects with: the register access, the record, the files and the changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * What the remover deletes a set's objects, files and changes with.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
final readonly class ExampleSetRemoverObjects {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ExampleSetStore   $store   Register access.
	 * @param ExampleSetRecord  $record  What the install made.
	 * @param ExampleSetFiles   $files   Deletes the files the set attached.
	 * @param ExampleSetChanges $changes Reverts changes to objects the set did not create.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function __construct(
		public ExampleSetStore $store,
		public ExampleSetRecord $record,
		public ExampleSetFiles $files,
		public ExampleSetChanges $changes,
	) {
	}//end __construct()
}//end class
