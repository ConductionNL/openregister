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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Controller\Support\PropertyVocabularyControllerServices;

/**
 * Discovery for the property vocabulary.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
class PropertyVocabularyController extends Controller {

	/**
	 * The published vocabulary.
	 *
	 * @var PropertyVocabularyInterface
	 */
	private readonly PropertyVocabularyInterface $vocabulary;

	/**
	 * The extending-form declaration reader.
	 *
	 * @var ExtendingFormDeclarationInterface
	 */
	private readonly ExtendingFormDeclarationInterface $declarations;

	/**
	 * Schema lookup, for the declared narrowings.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param PropertyVocabularyControllerServices $services The services, bundled.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		PropertyVocabularyControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->vocabulary = $services->vocabulary;
		$this->declarations = $services->declarations;
		$this->schemaMapper = $services->schemaMapper;

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
	 * @spec openspec/specs/runtime-schema-api/spec.md
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
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function extendingForms(): JSONResponse {
		$rows = $this->allDeclarationRows();

		return new JSONResponse(
			[
				'declarations' => $rows,
				'annotation' => ExtendingFormDeclarationInterface::ANNOTATION,
				'vocabularyKeys' => $this->vocabulary->keys(),
				'sourceAliases' => ExtendingFormDeclarationInterface::SOURCE_ALIASES,
				'formRoles' => ExtendingFormDeclarationInterface::FORM_ROLES,
				'counts' => ['declarations' => count($rows)],
			]
		);

	}//end extendingForms()

	/**
	 * The described extending-form declarations of every readable schema.
	 *
	 * @return array<int, array<string, mixed>> One row per declaration.
	 */
	private function allDeclarationRows(): array {
		$rows = [];
		foreach ($this->schemaMapper->findAll() as $schema) {
			array_push($rows, ...$this->declarationRows(schema: $schema));
		}

		return $rows;
	}//end allDeclarationRows()

	/**
	 * The described extending-form declarations of one schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> One row per declaration, naming the schema.
	 */
	private function declarationRows(Schema $schema): array {
		$found = $this->declarations->fromSchema(
			configuration: $this->arrayOr(value: $schema->getConfiguration()),
			properties: $this->arrayOr(value: $schema->getProperties())
		);

		$rows = [];
		foreach ($found as $path => $annotation) {
			$row = $this->declarations->describe(annotation: $annotation, path: $path);
			$row['schema'] = (string)($schema->getSlug() ?? '');
			$rows[] = $row;
		}

		return $rows;
	}//end declarationRows()

	/**
	 * The value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed> The array.
	 */
	private function arrayOr(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOr()
}//end class
