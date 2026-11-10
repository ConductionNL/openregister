<?php

/**
 * OpenRegister ExampleSetInstallerObjects
 *
 * The installer's object collaborators: register access, placeholders, the install record and the proof.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The installer's object collaborators: register access, placeholders, the install record and the proof.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
final readonly class ExampleSetInstallerObjects {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ExampleSetStore  $store  Register access.
	 * @param ExampleSetValues $values Fills placeholders.
	 * @param ExampleSetRecord $record What an install made.
	 * @param ExampleSetProof  $proof  Compares sent with stored.
	 *
	 * @return void
	 */
	public function __construct(
		public ExampleSetStore $store,
		public ExampleSetValues $values,
		public ExampleSetRecord $record,
		public ExampleSetProof $proof,
	) {
	}//end __construct()
}//end class
