<?php

/**
 * ExchangeAnnotationRefusals: annotations about how objects leave a schema, refused at save.
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
use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator;
use OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidators;

/**
 * Save-time checks of the handoff and MCP declarations, whose faults refuse the save with their codes.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class ExchangeAnnotationRefusals {

	/**
	 * Validate the optional `x-openregister-handoff` annotation (ADR-051).
	 *
	 * A malformed handoff declaration REJECTS the schema (contract: schema-save
	 * validation SHALL reject with the typed handoff-* error codes): unlike
	 * advisory annotations, a broken handoff would otherwise surface as a
	 * runtime conversion failure on user action.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	public function checkHandoff(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-handoff');
		if ($annotation->isBlock() === true) {
			$lifecycle = ['x-openregister-lifecycle' => $annotation->configured(key: 'x-openregister-lifecycle')];
			$annotation->faults()->refuseCoded(errors: SchemaAnnotationValidators::handoff()->validate($annotation->shape() + $lifecycle));
		}
	}//end checkHandoff()

	/**
	 * Validate the optional `handoffContract` binding block (ADR-051, provider side).
	 *
	 * When present, every mandatory contract field of each bound kind must map
	 * to an existing own property; otherwise the schema is rejected with
	 * `handoff-contract-incomplete` listing the missing fields. A schema that
	 * implements a kind with NO binding block passes untouched (it is simply not
	 * a handoff provider).
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the binding block is incomplete or malformed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Implementer omits a mandatory contract field)
	 */
	public function checkHandoffContract(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'handoffContract');
		if ($annotation->isWritten() === false) {
			return;
		}

		$shape = $annotation->shape();
		foreach (['implements', 'jsonld', 'x-schema-org'] as $key) {
			$shape[$key] = $annotation->configured(key: $key);
		}

		$annotation->faults()->refuseCoded(errors: SchemaAnnotationValidators::handoffContractBinding()->validate($shape));
	}//end checkHandoffContract()

	/**
	 * Validate the optional `x-openregister-mcp` annotation (ADR-063).
	 *
	 * This validates the declaration only: no MCP tool is emitted and no serving
	 * surface is altered here (that is the `or-mcp-derived-tool-provider`
	 * change). A malformed block fails the schema save loudly.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DIALECT-002 — Save-time validation of the dialect shape)
	 */
	public function checkMcp(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-mcp');
		if ($annotation->isBlock() === true) {
			$annotation->faults()->refuseCoded(errors: (new McpAnnotationValidator())->validate($annotation->shape()));
		}
	}//end checkMcp()
}//end class
