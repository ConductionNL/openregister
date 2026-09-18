<?php

/**
 * Whether a summary over a property may be shown to this caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/aggregate-paths-ask-permission/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One answer, asked in more places.
 *
 * 🔴 AN AGGREGATE IS A READ OF THE COLUMN FOR EVERYBODY IT IS SHOWN TO. A facet
 * returns the distinct values with counts; a sum over a salary nobody may read
 * IS the salary total; a kanban column heading is a value. openregister#3934
 * found the first of these and this class exists so the rest ask the same
 * question.
 *
 * 🔑 IT HOLDS NO RULE OF ITS OWN, ON PURPOSE. `PropertyRbacHandler` already
 * decides whether a caller may read a property, and the render, export and OAS
 * paths consult it. The moment this class decided anything itself there would be
 * two answers to one question, they would drift, and the wider one would be the
 * one that discloses.
 *
 * @spec openspec/changes/aggregate-paths-ask-permission/specs/rbac-scopes/spec.md
 */
class AggregateVisibility {

	/**
	 * The collaborators.
	 *
	 * @param PropertyRbacHandler|null $rbac   The one thing that decides property reads.
	 * @param LoggerInterface|null     $logger The logger.
	 */
	public function __construct(
		private readonly ?PropertyRbacHandler $rbac = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Whether a summary over this property may be shown.
	 *
	 * 🔑 THE CHECK PASSES AN EMPTY OBJECT, WHICH IS NOT AN OVERSIGHT. An
	 * aggregate is not about one record: it asks whether this property is
	 * readable AT ALL for this caller, not whether it is readable on some
	 * particular row. So a CONDITIONAL rule, one that depends on a record's
	 * contents, does not admit the aggregate.
	 *
	 * That is a real restriction and it is the safe direction: a property
	 * readable only on rows the caller owns is not summarisable by them,
	 * because a summary spans rows they do not own.
	 *
	 * FAILS CLOSED. With no way to ask, the summary is withheld, because the
	 * alternative is showing one whose access nobody checked.
	 *
	 * @param Schema|null $schema   The schema the property belongs to.
	 * @param string      $property The property name.
	 *
	 * @return bool Whether the summary may be shown.
	 */
	public function maySummarise(?Schema $schema, string $property): bool {
		if ($schema === null) {
			// No schema means no property-level rule to apply. A metadata
			// aggregate (@self.created and friends) reaches here, and those are
			// governed by row access alone.
			return true;
		}

		if ($schema->hasPropertyAuthorization() === false) {
			// Nothing on this schema is governed at property level, so there is
			// no question to ask and no handler to resolve. This short-circuit
			// is what keeps every ordinary aggregate on every ordinary schema
			// exactly as fast as it was.
			return true;
		}

		if ($this->rbac === null) {
			$this->warn(property: $property, reason: 'no property read rule available to ask');
			return false;
		}

		try {
			return $this->rbac->canReadProperty(schema: $schema, property: $property, object: []);
		} catch (Throwable $e) {
			$this->warn(property: $property, reason: $e->getMessage());
			return false;
		}
	}//end maySummarise()

	/**
	 * Split a set of fields into the ones that may be summarised and the rest.
	 *
	 * 🔴 THE WITHHELD NAMES COME BACK, AND THAT IS THE POINT OF RETURNING A
	 * PAIR. Dropping them silently leaves the caller unable to tell "this field
	 * has no values" from "this field is not yours", and the first is a claim
	 * about the data that the system has no business making on the second's
	 * behalf.
	 *
	 * @param Schema|null       $schema The schema.
	 * @param array<int, string> $fields The field names.
	 *
	 * @return array{allowed: array<int, string>, withheld: array<int, string>} The split.
	 */
	public function partition(?Schema $schema, array $fields): array {
		$allowed  = [];
		$withheld = [];

		foreach ($fields as $field) {
			if ($this->maySummarise(schema: $schema, property: (string)$field) === true) {
				$allowed[] = (string)$field;
				continue;
			}

			$withheld[] = (string)$field;
		}

		return [
			'allowed'  => $allowed,
			'withheld' => $withheld,
		];
	}//end partition()

	/**
	 * Say why a summary was withheld, once, at warning level.
	 *
	 * @param string $property The property.
	 * @param string $reason   Why.
	 *
	 * @return void
	 */
	private function warn(string $property, string $reason): void {
		$this->logger?->warning(
			message: '[AggregateVisibility] Withholding a summary: ' . $reason,
			context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property]
		);
	}//end warn()
}//end class
