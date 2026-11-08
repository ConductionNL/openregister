<?php

/**
 * AdvisoryAnnotationChecks: annotations whose faults a schema save only logs.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\SearchAnnotationValidators;
use OCA\OpenRegister\Service\Merge\MergeAnnotationValidator;
use OCA\OpenRegister\Service\Party\PartyAnnotationValidator;
use OCA\OpenRegister\Service\Quality\DedupAnnotationValidator;
use OCA\OpenRegister\Service\Quality\QualityAnnotationValidator;
use OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidators;
use Psr\Log\LoggerInterface;

/**
 * Save-time checks of the annotations that are metadata on top of a schema.
 *
 * Each of these is ADVISORY, not a storage requirement: the schema stores
 * objects perfectly well without it. A malformed block must not abort the whole
 * schema import, so a fault degrades to a warning.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class AdvisoryAnnotationChecks {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the warnings.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate the optional `x-openregister-aggregations` annotation.
	 *
	 * The schema still stores objects; the aggregation simply won't be runnable.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function checkAggregations(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-aggregations');
		if ($annotation->isBlock() === true) {
			$this->warn(
				errors: SearchAnnotationValidators::aggregation()->validate($annotation->shape()),
				text: $annotation->label() . ' is invalid and was ignored (aggregation not registered)'
			);
		}
	}//end checkAggregations()

	/**
	 * Validate the optional `x-openregister-quality` annotation.
	 *
	 * The schema still stores objects; the score simply won't be materialised.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function checkQuality(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-quality');
		if ($annotation->isBlock() === true) {
			$this->warn(
				errors: (new QualityAnnotationValidator())->validate($annotation->shape()),
				text: $annotation->label() . ' is invalid and was ignored (quality score not materialised)'
			);
		}
	}//end checkQuality()

	/**
	 * Validate the optional `x-openregister-dedup` annotation.
	 *
	 * Match rules are consumed on demand by DuplicateDetectionService, and
	 * duplicate detection simply falls back to caller-supplied rules.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function checkDedup(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-dedup');
		if ($annotation->isBlock() === true) {
			$this->warn(
				errors: (new DedupAnnotationValidator())->validate($annotation->shape()),
				text: $annotation->label() . ' is invalid and was ignored (declared match rules not used)'
			);
		}
	}//end checkDedup()

	/**
	 * Validate the lens, list and geographic-inheritance annotations.
	 *
	 * All three are declarative reading instructions: a malformed one costs a
	 * surface, never an object. It does warn, because the failure mode without
	 * it is a lens that renders empty forever and reads exactly like a field
	 * nobody filled in.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function checkHinge(Schema $schema): void {
		$keys = [Schema::LENS_ANNOTATION, Schema::LIST_ANNOTATION, Schema::GEO_INHERITANCE_ANNOTATION];
		$declared = array_intersect_key(($schema->getConfiguration() ?? []), array_flip($keys));
		$declared = array_filter($declared, static fn (mixed $value): bool => $value !== null);
		if ($declared === []) {
			return;
		}

		$this->warn(
			errors: SchemaAnnotationValidators::hinge()->validate(['properties' => ($schema->getProperties() ?? [])] + $declared),
			text: 'Hinge annotations on schema "' . ((string)($schema->getSlug() ?? '')) . '" are invalid and were ignored'
		);
	}//end checkHinge()

	/**
	 * Validate the optional `x-openregister-survivorship` annotation.
	 *
	 * The schema still stores objects; the golden record simply won't be materialised.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function checkSurvivorship(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-survivorship');
		if ($annotation->isBlock() === true) {
			$this->warn(
				errors: SchemaAnnotationValidators::survivorship()->validate($annotation->shape()),
				text: $annotation->label() . ' is invalid and was ignored (golden record not materialised)'
			);
		}
	}//end checkSurvivorship()

	/**
	 * Validate the optional `x-openregister-merge` annotation.
	 *
	 * Merge config is steward-action metadata; merges simply fall back to the
	 * documented defaults.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function checkMerge(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-merge');
		if ($annotation->isBlock() === true) {
			$this->warn(
				errors: (new MergeAnnotationValidator())->validate($annotation->shape()),
				text: $annotation->label() . ' is invalid and was ignored (merge falls back to defaults)'
			);
		}
	}//end checkMerge()

	/**
	 * Validate the optional `x-openregister-party` annotation.
	 *
	 * It is warned about rather than dropped: the failure a dropped declaration
	 * causes is a party schema that reads as an ordinary one, and the operator
	 * needs the field name to fix it.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function checkParty(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-party');
		if ($annotation->isBlock() === true) {
			$this->warn(
				errors: (new PartyAnnotationValidator())->validate($annotation->shape()),
				text: $annotation->label() . ' is invalid and was ignored (the schema is not treated as a party schema)'
			);
		}
	}//end checkParty()

	/**
	 * Log the given text with the messages of the errors, if there are any.
	 *
	 * @param array<int, array{message: string}> $errors The validator's errors.
	 * @param string                             $text   What the fault means for the schema.
	 *
	 * @return void
	 */
	private function warn(array $errors, string $text): void {
		if (count($errors) > 0) {
			$this->logger->warning(
				$text . ': ' . implode(' ', array_map(static fn (array $err) => $err['message'], $errors))
			);
		}
	}//end warn()
}//end class
