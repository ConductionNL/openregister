<?php

/**
 * OpenRegister Example Set Path Value
 *
 * A value to put at a dot path of an object an example set writes or restores.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * A dot path and the value that goes there, as {@see ExampleSetPaths::withPath()} writes it.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */
final class ExampleSetPathValue {

	/**
	 * Constructor.
	 *
	 * @param string $path  The dot path.
	 * @param mixed  $value The value.
	 */
	public function __construct(
		public readonly string $path,
		public readonly mixed $value,
	) {
	}//end __construct()

	/**
	 * The same value one level down: the path without its first segment.
	 *
	 * @param string $rest The path below the first segment.
	 *
	 * @return self
	 */
	public function below(string $rest): self {
		return new self(path: $rest, value: $this->value);
	}//end below()
}//end class
