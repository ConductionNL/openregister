<?php

/**
 * ArchivalDeclarationRefusals: the MDTO mapping and archival annotations a schema save refuses.
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
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Archival\InheritedAppraisal;
use OCA\OpenRegister\Service\Schemas\ArchivalDeclarationValidators;
use OCA\OpenRegister\Service\Schemas\RetentionAnnotationValidator;
use Psr\Log\LoggerInterface;

/**
 * Save-time checks of the MDTO element mapping, the archival annotation and the retention declaration.
 *
 * Compliance contributes them to the schema save through
 * SchemaDeclarationChecksEvent, in the MDTO_MAPPING, ARCHIVAL and RETENTION slots.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class ArchivalDeclarationRefusals {

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
			$validator = ArchivalDeclarationValidators::elementMapping();
			$annotation->faults()->refuseInvalid(
				errors: $validator->validate(mapping: $annotation->value(), properties: $schema->getProperties())
			);
		}
	}//end checkMdtoMapping()

	/**
	 * Validate the optional `x-openregister-archival` annotation.
	 *
	 * Errors are aggregated by the archival annotation validator and thrown here
	 * as a single message so callers see a clear schema-save failure.
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

		$validator = ArchivalDeclarationValidators::archival();
		$findings  = $validator->validate(schema: ['x-openregister-archival' => $annotation->value()]);
		$split     = $validator->partition(findings: $findings);
		if (count($split['warnings']) > 0) {
			$this->logger->warning($annotation->faults()->unknownKeysNotice(warnings: $split['warnings']));
		}

		$annotation->faults()->refuse(errors: $split['errors']);
	}//end checkArchival()

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
}//end class
