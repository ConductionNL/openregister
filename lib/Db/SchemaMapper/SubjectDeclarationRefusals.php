<?php

/**
 * SubjectDeclarationRefusals: the consent, review and registry declarations, refused at save.
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
use OCA\OpenRegister\Service\Consent\ConsentDeclarationException;
use OCA\OpenRegister\Service\Decision\ReviewDeclarationException;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;

/**
 * Save-time checks of declarations about consent, ownership of a review and inbound writes.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class SubjectDeclarationRefusals {

	/**
	 * Validate the `x-openregister-consent` annotation on a schema's properties.
	 *
	 * Blocking, and deliberately so (unlike the optional
	 * `x-openregister-notifications` annotation, which degrades to a warning):
	 * an evidentiary consent property that silently never fills evidence is
	 * worse than a save that names the mistake, since an app author would
	 * otherwise believe consent is being proven when it is not.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws ConsentDeclarationException When a declaration cannot be honoured.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function checkConsent(Schema $schema): void {
		$properties = $schema->getProperties();
		$declaring = array_filter(
			$properties,
			static fn (mixed $definition): bool => is_array($definition) === true && isset($definition['x-openregister-consent']) === true
		);
		if ($declaring === []) {
			return;
		}

		$errors = SchemaDeclarationValidators::consent()->validate(['properties' => $properties]);
		if ($errors !== []) {
			throw new ConsentDeclarationException(errors: $errors);
		}
	}//end checkConsent()

	/**
	 * Refuse an `x-openregister-review` declaration the ownership rule cannot honour.
	 *
	 * Refused rather than logged: a mistyped ownership read as open would leave
	 * a four-eyes review open while its author believes it is closed.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @throws ReviewDeclarationException When the declaration is unsound.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function checkReview(Schema $schema): void {
		$errors = SchemaDeclarationValidators::review()->validate(configuration: ($schema->getConfiguration() ?? []));
		if ($errors !== []) {
			throw new ReviewDeclarationException(errors: $errors);
		}
	}//end checkReview()

	/**
	 * Validate the optional `x-openregister-registry` annotation.
	 *
	 * Per `registry-subscriptions` REQ 1, an annotation naming an `identity` or
	 * `owned` property the schema does not declare MUST refuse the save: unlike
	 * most other `x-openregister-*` dialects, this one is BLOCKING, not advisory,
	 * because a stored-but-wrong annotation would let the inbound registry
	 * endpoint write a property nobody reviewed.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
	 */
	public function checkRegistry(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-registry');
		if ($annotation->isBlock() === true) {
			$annotation->faults()->refuseCoded(errors: SchemaDeclarationValidators::registry()->validate($annotation->shape()));
		}
	}//end checkRegistry()
}//end class
