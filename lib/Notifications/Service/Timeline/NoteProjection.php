<?php

/**
 * A note written through the notes endpoint, on its way to a record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

/**
 * A note as the notes endpoint returned it, with the coordinates the caller addressed.
 */
class NoteProjection {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $note     The note as the notes endpoint returned it.
	 * @param string|null         $register The register as the caller addressed it.
	 * @param string|null         $schema   The schema as the caller addressed it.
	 */
	public function __construct(
		private readonly array $note,
		private readonly ?string $register = null,
		private readonly ?string $schema = null,
	) {
	}//end __construct()

	/**
	 * The note's text.
	 *
	 * @return string The text.
	 */
	public function message(): string {
		return (string)($this->note['message'] ?? '');
	}//end message()

	/**
	 * The note's comment id for a log line.
	 *
	 * @return string The id, or a question mark.
	 */
	public function label(): string {
		return (string)($this->note['id'] ?? '?');
	}//end label()

	/**
	 * The payload of the record this note becomes.
	 *
	 * @return array<string,mixed> The payload.
	 */
	public function recordData(): array {
		return [
			'message' => $this->message(),
			'commentId' => ($this->note['id'] ?? null),
			'visibility' => ($this->note['visibility'] ?? null),
			'register' => $this->register,
			'schema' => $this->schema,
		];
	}//end recordData()

	/**
	 * The register and schema labels of the derived steps.
	 *
	 * @return array<string,string|null> The labels.
	 */
	public function labels(): array {
		return ['register' => $this->register, 'schema' => $this->schema];
	}//end labels()
}//end class
