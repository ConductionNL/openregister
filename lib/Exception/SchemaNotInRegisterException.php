<?php

/**
 * OpenRegister SchemaNotInRegisterException
 *
 * This file contains the exception thrown when a schema slug does not resolve
 * within the register the caller named.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Thrown when a schema slug does not resolve inside the register that was named.
 *
 * Extends {@see DoesNotExistException} so Nextcloud's dispatcher keeps mapping it
 * to a 404 — no controller or API contract changes. The distinct type exists so a
 * caller (and a test) can tell "this register does not carry that slug" apart from
 * "no such register" and from "no such schema anywhere".
 *
 * The message deliberately carries the candidate count and the repair command.
 * Measured on the development instance 2026-08-16: register `document` (id 6) had
 * an empty `schemas` list while nine `docudesk`-owned schemas shared the slug
 * `anonymizationLink`. A bare "schema not found" reads as "your slug is wrong",
 * which is the one conclusion that is certainly false when nine copies exist. The
 * count is what turns a dead end into a diagnosis.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */
class SchemaNotInRegisterException extends DoesNotExistException {

	/**
	 * The id of the register the caller named.
	 *
	 * @var int|null
	 */
	private ?int $registerId;

	/**
	 * The slug of the register the caller named, when known.
	 *
	 * @var string|null
	 */
	private ?string $registerSlug;

	/**
	 * The schema slug that failed to resolve inside that register.
	 *
	 * @var string
	 */
	private string $schemaSlug;

	/**
	 * How many schemas carry this slug elsewhere on the instance.
	 *
	 * @var int
	 */
	private int $candidatesElsewhere;

	/**
	 * Constructor.
	 *
	 * @param string      $schemaSlug          The slug that did not resolve.
	 * @param int|null    $registerId          The named register's id, when known.
	 * @param string|null $registerSlug        The named register's slug, when known.
	 * @param int         $candidatesElsewhere Count of same-slug schemas outside the register.
	 * @param bool        $registerListEmpty   Whether the register carries no schemas at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function __construct(
		string $schemaSlug,
		?int $registerId=null,
		?string $registerSlug=null,
		int $candidatesElsewhere=0,
		bool $registerListEmpty=false,
	) {
		$this->schemaSlug          = $schemaSlug;
		$this->registerId          = $registerId;
		$this->registerSlug        = $registerSlug;
		$this->candidatesElsewhere = $candidatesElsewhere;

		parent::__construct(
			msg: $this->composeMessage(registerListEmpty: $registerListEmpty)
		);
	}//end __construct()

	/**
	 * Build the operator-facing message.
	 *
	 * Every branch names the repair command. A register whose schemas list is empty
	 * gets a different sentence from one that simply lacks this slug, because the
	 * two have different remedies and conflating them is what hid the defect.
	 *
	 * @param bool $registerListEmpty Whether the register carries no schemas at all.
	 *
	 * @return string The composed message.
	 */
	private function composeMessage(bool $registerListEmpty): string {
		$register = 'the named register';
		if ($this->registerId !== null) {
			$register = sprintf(
				'register "%s" (id %d)',
				($this->registerSlug ?? '?'),
				$this->registerId
			);
		}

		if ($registerListEmpty === true) {
			return sprintf(
				'Schema slug "%s" cannot resolve because %s carries no schemas at all. '
				. '%d schema(s) elsewhere on this instance carry this slug, and resolving to one of '
				. 'them would bind data to a register its owner did not choose. '
				. 'Run `occ openregister:registers:relink-schemas` to inspect and repair the linkage.',
				$this->schemaSlug,
				$register,
				$this->candidatesElsewhere
			);
		}

		return sprintf(
			'Schema slug "%s" is not carried by %s. %d schema(s) elsewhere on this instance '
			. 'carry this slug; none of them is served here, because naming a register makes it a boundary. '
			. 'If the linkage was lost, run `occ openregister:registers:relink-schemas` to inspect and repair it.',
			$this->schemaSlug,
			$register,
			$this->candidatesElsewhere
		);
	}//end composeMessage()

	/**
	 * Get the named register's id.
	 *
	 * @return int|null The register id, or null when it was not known.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function getRegisterId(): ?int {
		return $this->registerId;
	}//end getRegisterId()

	/**
	 * Get the named register's slug.
	 *
	 * @return string|null The register slug, or null when it was not known.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function getRegisterSlug(): ?string {
		return $this->registerSlug;
	}//end getRegisterSlug()

	/**
	 * Get the schema slug that failed to resolve.
	 *
	 * @return string The schema slug.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function getSchemaSlug(): string {
		return $this->schemaSlug;
	}//end getSchemaSlug()

	/**
	 * Get the count of same-slug schemas outside the register.
	 *
	 * @return int The candidate count.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function getCandidatesElsewhere(): int {
		return $this->candidatesElsewhere;
	}//end getCandidatesElsewhere()
}//end class
