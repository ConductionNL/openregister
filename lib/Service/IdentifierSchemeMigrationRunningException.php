<?php

/**
 * IdentifierSchemeMigrationRunningException — a second migration of a sequence that is being migrated.
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
use RuntimeException;
use Throwable;

/**
 * Refuses a scheme migration while another migration of the same sequence runs.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */
class IdentifierSchemeMigrationRunningException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                   $sequence The sequence asked for.
	 * @param IdentifierSchemeRun|null $running  The run already migrating it, when it could be read.
	 * @param Throwable|null           $previous The store failure that revealed it, if any.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function __construct(
		string $sequence,
		private readonly ?IdentifierSchemeRun $running=null,
		?Throwable $previous=null,
	) {
		$named = '';
		if ($running !== null) {
			$named = sprintf(
				' (run %d on schema %d, property "%s", started by %s)',
				(int)$running->getId(),
				(int)$running->getSchemaId(),
				(string)$running->getProperty(),
				($running->getStartedBy() ?? 'the system')
			);
		}

		parent::__construct(
			message: sprintf('Sequence "%s" is already being migrated%s; wait until that migration has finished.', $sequence, $named),
			previous: $previous
		);
	}//end __construct()

	/**
	 * The run already migrating the sequence, when it could be read.
	 *
	 * @return IdentifierSchemeRun|null
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function getRunning(): ?IdentifierSchemeRun {
		return $this->running;
	}//end getRunning()
}//end class
