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
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolverInterface;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;
use Psr\Log\LoggerInterface;

/**
 * Save-time checks of annotations whose faults are silent later, so the save refuses them.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class RecordAnnotationRefusals {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the notice about unknown keys.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate the optional `x-openregister-mdto-mapping` annotation.
	 *
	 * 🔴 EVERY ERROR HERE REFUSES THE SAVE, unlike the lifecycle block. Two
	 * reasons. The key is new, so no register ships one and refusing breaks no
	 * existing import, which is exactly the argument that made the lifecycle
	 * condition codes blocking. And a mapping stored broken is worse than no
	 * mapping: `MdtoPreconditions` refuses a transfer the moment a schema
	 * declares a mapping that does not fill a mandatory element, so an
	 * administrator who saved a typo would find out when a transfer they had
	 * scheduled stopped, with the schema editor having reported success.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the mapping names an element MDTO does not have, a
	 *                    property the schema does not declare, no source, two
	 *                    sources, or leaves a mandatory element unfilled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function checkMdtoMapping(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: ElementMappingValidatorInterface::ANNOTATION_KEY);
		if ($annotation->isBlock() === true) {
			$validator = SchemaDeclarationValidators::elementMapping();
			$annotation->faults()->refuseInvalid(
				errors: $validator->validate(mapping: $annotation->value(), properties: $schema->getProperties())
			);
		}
	}//end checkMdtoMapping()

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
	 * Validate the optional `x-openregister-archival` annotation.
	 *
	 * Errors are aggregated by ArchivalAnnotationValidator and thrown here as a
	 * single message so callers see a clear schema-save failure.
	 *
	 * An UNKNOWN key is surfaced and ignored, never fatal: the same rule R07
	 * applies to an unknown `x-openregister-*` key one level up. It declares
	 * nothing, so dropping it loses nothing, whereas refusing it refuses the
	 * whole schema: at import time that costs the register every object of that
	 * schema, and the operator sees it as a seeding failure several layers away
	 * from the annotation.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function checkArchival(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-archival');
		if ($annotation->isBlock() === false) {
			return;
		}

		$validator = SchemaDeclarationValidators::archival();
		$findings = $validator->validate(schema: ['x-openregister-archival' => $annotation->value()]);
		$split = $validator->partition(findings: $findings);
		if (count($split['warnings']) > 0) {
			$this->logger->warning($annotation->faults()->unknownKeysNotice(warnings: $split['warnings']));
		}

		$annotation->faults()->refuse(errors: $split['errors']);
	}//end checkArchival()

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
}//end class
