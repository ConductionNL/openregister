<?php

/**
 * RecordAnnotationRefusals: annotations about what a record holds, refused at save.
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
use OCA\OpenRegister\Exception\UniqueHintException;
use OCA\OpenRegister\Search\Service\SearchAnnotationValidators;
use OCA\OpenRegister\Service\Archival\InheritedAppraisal;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolverInterface;
use OCA\OpenRegister\Service\Schemas\RetentionAnnotationValidator;
use OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration;
use OCA\OpenRegister\Service\Search\SearchResultDeclaration;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;

/**
 * Save-time checks of annotations whose faults are silent later, so the save refuses them.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class RecordAnnotationRefusals {

	/**
	 * Validate the optional `x-openregister-unique-hint` annotation.
	 *
	 * FATAL, unlike {@see AdvisoryAnnotationChecks::checkDedup()}, which degrades
	 * a malformed block to a logged warning. The difference is the cost of being
	 * wrong. A malformed dedup block costs a duplicate sweep you can re-run; a
	 * nomination of a property that does not exist costs a uniqueness alert that
	 * silently never fires, and the only symptom is a second case on the same
	 * KvK number found months later or never. The administrator who typed the
	 * name is the one person who can fix it, and they are still holding the form.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws UniqueHintException When a nomination names a property the schema does not declare.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function checkUniqueHint(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-unique-hint');
		if ($annotation->isWritten() === false) {
			return;
		}

		$errors = SearchAnnotationValidators::uniqueHint()->validate($annotation->shape());
		if (count($errors) > 0) {
			$messages = array_map(static fn (array $err) => $err['message'], $errors);
			throw new UniqueHintException(
				message: 'x-openregister-unique-hint: ' . implode(' ', $messages),
				errors: $errors
			);
		}
	}//end checkUniqueHint()

	/**
	 * Validate the optional `x-openregister-widgets` annotation.
	 *
	 * Schemas that pre-declare widgets (e.g. the `dashboard` schema in the
	 * `reports` register) carry the array under
	 * `configuration['x-openregister-widgets']`. Operators see shape errors at
	 * schema-save time rather than at first render.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function checkWidgets(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-widgets');
		if ($annotation->isBlock() === true) {
			$annotation->faults()->refuse(
				errors: SchemaDeclarationValidators::widgets()->validate(['x-openregister-widgets' => $annotation->value()])
			);
		}
	}//end checkWidgets()

	/**
	 * Refuse an `x-openregister-retention` declaration that names a property the
	 * schema lacks, or one that holds no reference.
	 *
	 * It throws rather than warns: a declaration that follows nothing holds no
	 * record back, and the first anyone would learn of it is a destroyed record
	 * whose parent was meant to be kept.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When the declaration cannot be followed.
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
	 */
	public function checkRetention(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: InheritedAppraisal::ANNOTATION);
		if ($annotation->isWritten() === true) {
			$annotation->faults()->refuseWith(
				messages: (new RetentionAnnotationValidator())->validate(annotation: $annotation->value(), properties: ($schema->getProperties() ?? []))
			);
		}
	}//end checkRetention()

	/**
	 * Refuse a broken `x-openregister-external-links` declaration at save.
	 *
	 * This one throws rather than warns, and the reason is the feature's own
	 * failure mode. A link whose placeholder cannot be filled is HIDDEN by
	 * design, because a URL with `{bagId}` still in it is a broken link that
	 * looks like a working one. That makes a misspelt placeholder and an object
	 * with no value indistinguishable at render: an author would read a 200,
	 * see no link, and have no way to tell which of the two happened. The save
	 * is the only moment the two can be told apart, so it is the only moment
	 * worth refusing at.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When a declaration cannot produce a link.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	public function checkExternalLinks(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: ExternalLinkResolverInterface::ANNOTATION);
		if ($annotation->hasValue() === true) {
			$annotation->faults()->refuseWith(messages: SchemaDeclarationValidators::externalLinks()->validate($annotation->shape()));
		}
	}//end checkExternalLinks()

	/**
	 * Refuse a save whose `x-openregister-card` names a property this schema does not have.
	 *
	 * A card that names a property that is not there would render an empty row
	 * in a chat window, where nobody can see why.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When the card names something the schema does not have.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public function checkReferenceCard(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: ReferenceCardDeclaration::ANNOTATION);
		$annotation->faults()->refuseWith(
			messages: ReferenceCardDeclaration::faults(
				block: $annotation->value(),
				properties: ($schema->getProperties() ?? [])
			)
		);
	}//end checkReferenceCard()
	/**
	 * Refuse a save whose `x-openregister-search-result` names a property this schema does not have.
	 *
	 * A declared title that is not there would leave every result of the
	 * schema on the derived title while the schema says otherwise.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When the declaration names something the schema does not have.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
	 */
	public function checkSearchResult(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: SearchResultDeclaration::ANNOTATION);
		$annotation->faults()->refuseWith(
			messages: SearchResultDeclaration::faults(
				block: $annotation->value(),
				properties: ($schema->getProperties() ?? [])
			)
		);
	}//end checkSearchResult()
}//end class
