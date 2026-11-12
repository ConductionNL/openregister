<?php

/**
 * ReferenceLoopCheck: save-time refusal of a reference that comes back to itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDeclarations;

/**
 * Refuses a schema whose properties or action inputs hold a reference loop.
 *
 * Such a reference constrains nothing: validation stops at the second visit.
 * Its author believes it says something, so the save is where to hear otherwise.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ReferenceLoopCheck {
	/**
	 * Refuse a schema that declares a reference loop.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a reference comes back to itself.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The declarations are read by a pure reader of the configuration.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function check(Schema $schema): void {
		$refusals = $this->refusals(label: 'Schema', document: ['properties' => $schema->getProperties()]);
		$declarations = ObjectActionDeclarations::declarations(configuration: ($schema->getConfiguration() ?? []));
		foreach ($declarations as $key => $definition) {
			$inputs = (array)($definition['inputs'] ?? []);
			$refusals = array_merge($refusals, $this->refusals(label: sprintf('Action "%s" inputs', $key), document: $inputs));
		}

		if ($refusals !== []) {
			throw new InvalidArgumentException(implode(' ', $refusals));
		}
	}//end check()

	/**
	 * One sentence per reference loop in a document.
	 *
	 * @param string               $label    What the document is, for the sentence.
	 * @param array<string, mixed> $document The JSON Schema document.
	 *
	 * @return array<int, string> The refusals.
	 */
	private function refusals(string $label, array $document): array {
		return array_map(
			static fn (string $pointer): string => sprintf(
				'%s: the $ref at "#%s" comes back to itself without moving through the data.',
				$label,
				$pointer
			),
			(new ReferenceLoops(document: $document))->pointers()
		);
	}//end refusals()
}//end class
