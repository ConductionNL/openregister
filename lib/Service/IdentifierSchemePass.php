<?php

/**
 * IdentifierSchemePass — one batch's run, schema and its two declarations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationInterface;

/**
 * What a batch of an identifier scheme migration works with.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */
final class IdentifierSchemePass {

	/**
	 * Constructor.
	 *
	 * @param IdentifierSchemeRun                     $run      The run.
	 * @param Schema                                  $schema   The schema of the objects.
	 * @param GeneratedIdentifierDeclarationInterface $current  The format the values are rewritten to.
	 * @param GeneratedIdentifierDeclarationInterface $previous The format the values were issued in.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function __construct(
		public readonly IdentifierSchemeRun $run,
		public readonly Schema $schema,
		public readonly GeneratedIdentifierDeclarationInterface $current,
		public readonly GeneratedIdentifierDeclarationInterface $previous,
	) {
	}//end __construct()
}//end class
