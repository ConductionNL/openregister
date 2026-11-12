<?php

/**
 * A place in a repeating group: the group, and optionally a row and a member.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * What a refusal is about, and what the group declares there.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class RepeatingGroupPlace {

	/**
	 * The row, counted from one, or null for the group as a whole.
	 *
	 * @var integer|null
	 */
	private ?int $position = null;

	/**
	 * The member inside the row, or null.
	 *
	 * @var string|null
	 */
	private ?string $member = null;

	/**
	 * Constructor.
	 *
	 * @param string $name       The group's property name.
	 * @param array  $definition The group's declaration.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly string $name,
		private readonly array $definition,
	) {
	}//end __construct()

	/**
	 * This group at one row.
	 *
	 * @param integer $position The row, counted from one.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function atRow(int $position): self {
		$place = clone $this;
		$place->position = $position;
		return $place;
	}//end atRow()

	/**
	 * This row at one member.
	 *
	 * @param string $member The member name.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function atMember(string $member): self {
		$place = clone $this;
		$place->member = $member;
		return $place;
	}//end atMember()

	/**
	 * The row, counted from one.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function position(): ?int {
		return $this->position;
	}//end position()

	/**
	 * A numeric bound of the group, such as `minItems`.
	 *
	 * @param string $keyword The keyword.
	 *
	 * @return integer|null The bound, or null when there is none.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function bound(string $keyword): ?int {
		$bound = ($this->definition[$keyword] ?? null);
		if (is_numeric($bound) === false) {
			return null;
		}

		return (int)$bound;
	}//end bound()

	/**
	 * The declared members.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function members(): array {
		return $this->listAt(key: 'properties');
	}//end members()

	/**
	 * The members a row has to answer.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function required(): array {
		return $this->listAt(key: 'required');
	}//end required()

	/**
	 * Build one violation in the shape every caller reads.
	 *
	 * @param string $code    The machine-readable refusal code.
	 * @param string $message The sentence a person reads.
	 *
	 * @return array{property: string, position: int|null, member: string|null, code: string, message: string}
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function violation(string $code, string $message): array {
		return [
			'property' => $this->name,
			'position' => $this->position,
			'member' => $this->member,
			'code' => $code,
			'message' => $message,
		];
	}//end violation()

	/**
	 * The array under a key of `items`, or nothing.
	 *
	 * @param string $key The key.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function listAt(string $key): array {
		$value = ($this->definition['items'][$key] ?? []);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listAt()
}//end class
