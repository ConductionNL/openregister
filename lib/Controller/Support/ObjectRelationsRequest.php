<?php

/**
 * OpenRegister object relations request parameters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\IRequest;

/**
 * Reads the parameters the object relations routes take from the request.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ObjectRelationsRequest {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request the parameters come from.
	 */
	public function __construct(
		public readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The depth this request asks for.
	 *
	 * @return int The depth.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function depth(): int {
		$depth = $this->request->getParam('depth', 1);

		if (is_numeric($depth) === false) {
			return 1;
		}

		return max(1, (int)$depth);
	}//end depth()

	/**
	 * The depth this request asks for, or null when it names none.
	 *
	 * @return int|null The depth.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function optionalDepth(): ?int {
		if ($this->stringParam(name: 'depth') === null) {
			return null;
		}

		return $this->depth();
	}//end optionalDepth()

	/**
	 * The language the caller asked for, defaulting to Dutch.
	 *
	 * @return string The BCP-47 tag.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function language(): string {
		$explicit = trim((string)$this->request->getParam('language', ''));

		if ($explicit !== '') {
			return $explicit;
		}

		return 'nl';
	}//end language()

	/**
	 * Read a string request parameter, or null when it is absent or empty.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringParam()

	/**
	 * Read a comma-separated request parameter as its non-blank, trimmed items.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return array<int, string> The items.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function csvParam(string $name): array {
		return array_values(
			array_filter(
				array_map('trim', explode(',', (string)$this->stringParam(name: $name))),
				static fn (string $field): bool => $field !== ''
			)
		);
	}//end csvParam()

	/**
	 * Read a list request parameter, comma-separated or repeated, or null when it is absent.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return array<int, string>|null The values, without blanks.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function listParam(string $name): ?array {
		$value = $this->request->getParam($name);
		if ($value === null) {
			return null;
		}

		if (is_array($value) === false) {
			$value = explode(',', (string)$value);
		}

		$items = array_map(static fn (mixed $item): string => trim((string)$item), $value);

		return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
	}//end listParam()

	/**
	 * An object's register or schema reference as an id, when it is a numeric one.
	 *
	 * @param string|null $reference The reference as the object holds it.
	 *
	 * @return int|null The id, or null for none or a slug.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function idOf(?string $reference): ?int {
		if ($reference === null || is_numeric($reference) === false) {
			return null;
		}

		return (int)$reference;
	}//end idOf()
}//end class
