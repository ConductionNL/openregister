<?php

/**
 * OpenRegister ExampleSetInstallerWriters
 *
 * The installer's writers beside the objects: changes, files, engine rows and the refresh rewriter.
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
 * The installer's writers beside the objects: changes, files, engine rows and the refresh rewriter.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
final readonly class ExampleSetInstallerWriters {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ExampleSetChanges          $changes    Changes objects the set did not create.
	 * @param ExampleSetFiles            $files      Attaches files to objects.
	 * @param ExampleSetEngineKinds|null $engineRows Writes the tasks and saved views a fragment declares; null refuses them.
	 * @param ExampleSetRewriter         $rewriter   Rewrites a kept object on `--refresh`.
	 *
	 * @return void
	 */
	public function __construct(
		public ExampleSetChanges $changes,
		public ExampleSetFiles $files,
		public ?ExampleSetEngineKinds $engineRows=null,
		public ExampleSetRewriter $rewriter=new ExampleSetRewriter(),
	) {
	}//end __construct()
}//end class
