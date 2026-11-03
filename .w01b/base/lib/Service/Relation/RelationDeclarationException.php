<?php

/**
 * A relation declaration the schema cannot be saved with.
 *
 * Thrown by {@see \OCA\OpenRegister\Db\SchemaMapper} when a property's
 * `x-openregister-relation` or a schema's `x-openregister-relation-types`
 * cannot be honoured. It is blocking rather than advisory for the reason the
 * calculation path is: both keys are new, so no register carries one yet and
 * refusing here breaks no existing import, and a relation stored broken is
 * worse than one refused. A symmetric relation that also carries an
 * `inverseLabel` reads one way on one side and the other way on the other,
 * and a `type` naming a vocabulary entry that does not exist renders as the
 * fallback "referenced by" forever while its author believes it is typed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use Exception;

/**
 * Raised when a relation declaration is refused at schema save time.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationDeclarationException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The refusals.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(private readonly array $errors) {
		$messages = array_map(
			static fn (array $error): string => (string)($error['message'] ?? ''),
			$errors
		);

		parent::__construct(message: 'Invalid relation declaration: '.implode(' ', $messages));
	}//end __construct()

	/**
	 * The individual refusals, so the response names each property.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
