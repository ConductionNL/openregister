<?php

/**
 * PropertyVocabularyController — the published property vocabulary.
 *
 * Answers what a property may be: every type the layer accepts, the constraint
 * keys each type takes, the formats it supports, what converting a populated
 * property to it costs, and which apps narrow that list in their own form.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Discovery for the property vocabulary.
 */
class PropertyVocabularyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param PropertyVocabulary $vocabulary The published vocabulary.
	 * @param ExtendingFormDeclaration $declarations The extending-form declaration reader.
	 * @param SchemaMapper $schemaMapper Schema lookup, for the declared narrowings.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PropertyVocabulary $vocabulary,
		private readonly ExtendingFormDeclaration $declarations,
		private readonly SchemaMapper $schemaMapper,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every property type the layer accepts, with its constraints and formats.
	 *
	 * Read-only discovery of a static vocabulary: no object, no schema and no
	 * user data is reached, so any signed-in user may generate a property
	 * editor from it. There is nothing per-object to guard.
	 *
	 * @return JSONResponse The types, constraints, modifiers and pass-through keys.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function index(): JSONResponse {
		return new JSONResponse($this->vocabulary->all());

	}//end index()

	/**
	 * Every declared extending form, with what it forwards and what it leaves out.
	 *
	 * An app whose own form authors schema properties declares the vocabulary
	 * keys that form forwards. This read is what makes the narrowing countable:
	 * `narrows` is the vocabulary minus the declaration, per app.
	 *
	 * Schemas are read through the RBAC- and tenancy-scoped mapper, so a caller
	 * only sees declarations on schemas they may already read. That lookup is
	 * the per-object authorisation guard for this method.
	 *
	 * @return JSONResponse The declarations found, each with its narrowing.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function extendingForms(): JSONResponse {
		$rows = [];
		foreach ($this->schemaMapper->findAll() as $schema) {
			$configuration = ($schema->getConfiguration() ?? []);
			$properties = ($schema->getProperties() ?? []);
			if (is_array($configuration) === false) {
				$configuration = [];
			}

			if (is_array($properties) === false) {
				$properties = [];
			}

			$found = $this->declarations->fromSchema(configuration: $configuration, properties: $properties);
			foreach ($found as $path => $annotation) {
				$row = $this->declarations->describe(annotation: $annotation, path: $path);
				$row['schema'] = (string)($schema->getSlug() ?? '');
				$rows[] = $row;
			}
		}

		return new JSONResponse(
			[
				'declarations' => $rows,
				'annotation' => ExtendingFormDeclaration::ANNOTATION,
				'vocabularyKeys' => $this->vocabulary->keys(),
				'counts' => ['declarations' => count($rows)],
			]
		);

	}//end extendingForms()
}//end class
