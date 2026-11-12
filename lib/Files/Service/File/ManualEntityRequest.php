<?php

/**
 * OpenRegister ManualEntityRequest.
 *
 * Immutable input of one manual-entity add: the target file, the
 * operator-supplied value and type, the match options and the actor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCP\IUser;

/**
 * Input of `ManualEntityService::addManualEntity`.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class ManualEntityRequest {
	/**
	 * Match whole words only.
	 *
	 * @var boolean
	 */
	private bool $wholeWord = false;

	/**
	 * Match case-sensitively.
	 *
	 * @var boolean
	 */
	private bool $caseSensitive = false;

	/**
	 * Constructor.
	 *
	 * @param int $fileId Nextcloud file id the manual entity applies to.
	 * @param string $value Operator-supplied text (PII, audit-only).
	 * @param string $type Entity type tag (e.g. `PERSON`).
	 * @param IUser $actor The acting user.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $value,
		public readonly string $type,
		public readonly IUser $actor,
	) {

	}//end __construct()

	/**
	 * The same request with the given match options.
	 *
	 * @param bool $wholeWord Match whole words only.
	 * @param bool $caseSensitive Match case-sensitively.
	 *
	 * @return self The new request.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function withMatching(bool $wholeWord, bool $caseSensitive): self {
		$copy = clone $this;
		$copy->wholeWord = $wholeWord;
		$copy->caseSensitive = $caseSensitive;
		return $copy;

	}//end withMatching()

	/**
	 * Whether only whole words match.
	 *
	 * @return bool True for whole-word matching.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function wholeWord(): bool {
		return $this->wholeWord;

	}//end wholeWord()

	/**
	 * Whether matching is case-sensitive.
	 *
	 * @return bool True for case-sensitive matching.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function caseSensitive(): bool {
		return $this->caseSensitive;

	}//end caseSensitive()
}//end class
