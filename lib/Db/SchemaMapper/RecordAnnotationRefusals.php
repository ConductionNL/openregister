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
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolverInterface;
use OCA\OpenRegister\Service\Search\SearchResultDeclaration;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;

/**
 * Save-time checks of annotations whose faults are silent later, so the save refuses them.
 *
 * The widget annotation is Search's: SearchDeclarationChecksListener contributes
 * its check in the WIDGETS slot of SchemaDeclarationOrder.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class RecordAnnotationRefusals {

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
