<?php

/**
 * CalculationsController — the authoring surface for JSON-AST calculations.
 *
 * Publishes the operator catalogue an expression builder is generated from,
 * and evaluates a declaration that has not been saved yet against a sample
 * payload or a named object.
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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\CalculationsControllerServices;
use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\Calculation\CalculationTrialServiceInterface;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogueInterface;
use OCA\OpenRegister\Service\Calculation\TrialObject;
use OCA\OpenRegister\Service\Calculation\UnresolvedTrialObject;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Discovery and dry run for the JSON-AST calculation engine.
 */
class CalculationsController extends Controller {

	/**
	 * The published operator catalogue.
	 *
	 * @var OperatorCatalogueInterface
	 */
	private readonly OperatorCatalogueInterface $catalogue;

	/**
	 * Dry-run evaluation of an unsaved declaration.
	 *
	 * @var CalculationTrialServiceInterface
	 */
	private readonly CalculationTrialServiceInterface $trials;

	/**
	 * Register and schema lookups, each scoped to the caller by RBAC and tenancy.
	 *
	 * @var SchemaCatalogInterface
	 */
	private readonly SchemaCatalogInterface $schemaCatalog;

	/**
	 * Object lookup, scoped to the caller by RBAC and tenancy.
	 *
	 * @var MagicTableRowReaderInterface
	 */
	private readonly MagicTableRowReaderInterface $objectMapper;

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request  Request.
	 * @param CalculationsControllerServices $services The calculations controller services.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		CalculationsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->catalogue = $services->catalogue;
		$this->trials = $services->trials;
		$this->schemaCatalog = $services->schemaCatalog;
		$this->objectMapper = $services->objectMapper;

	}//end __construct()

	/**
	 * Every operator the evaluator accepts, with its arity, operand types,
	 * result type and a sentence.
	 *
	 * Read-only discovery of a static vocabulary: no object, no schema and no
	 * user data is reached, so any signed-in user may generate an expression
	 * builder from it. There is nothing per-object to guard.
	 *
	 * @return JSONResponse The catalogue rows and the categories they group under.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function operators(): JSONResponse {
		return new JSONResponse(
			[
				'operators' => $this->catalogue->all(),
				'categories' => $this->catalogue->categories(),
			]
		);

	}//end operators()

	/**
	 * Evaluate an unsaved calculation declaration and return the value or the error.
	 *
	 * Two payload shapes. With `object` the declaration runs against that
	 * sample payload and no stored data is touched at all. With `register`,
	 * `schema` and `objectId` it runs against the named object, which
	 * readableObject() looks up with RBAC and tenancy switched on: a caller
	 * who may not read that object gets a not-found refusal rather than its
	 * values. That lookup is the per-object authorisation guard for this
	 * method, and it runs before anything is evaluated.
	 *
	 * Nothing is written on either path: no schema is saved, no object is
	 * saved, and a `sequence` node yields null instead of reserving a number.
	 *
	 * @return JSONResponse The value with its derived dependencies, or the error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function evaluate(): JSONResponse {
		$declaration = $this->request->getParam('calculation');
		if (is_array($declaration) === false || isset($declaration['expression']) === false) {
			return $this->noDeclaration();
		}

		$target = $this->objectTarget();
		if ($target !== null) {
			return $this->trialAnswer(result: $this->objectTrial(declaration: $declaration, target: $target));
		}

		return $this->trialAnswer(result: $this->sampleTrial(declaration: $declaration));

	}//end evaluate()

	/**
	 * The refusal of a trial that carries no declaration with an expression.
	 *
	 * @return JSONResponse 422 naming what is required
	 */
	private function noDeclaration(): JSONResponse {
		return new JSONResponse(
			[
				'ok' => false,
				'error' => [
					'code' => 'calculation-trial-no-declaration',
					'message' => 'A "calculation" object carrying an "expression" is required.',
				],
			],
			422
		);
	}//end noDeclaration()

	/**
	 * Try the declaration against the stored object the request names.
	 *
	 * The object is looked up with RBAC and tenancy on before anything is
	 * evaluated; an object the caller may not read is a not-found result.
	 *
	 * @param array<string, mixed>  $declaration The calculation declaration
	 * @param array<string, string> $target      The register, schema and objectId
	 *
	 * @return array<string, mixed> The trial result
	 */
	private function objectTrial(array $declaration, array $target): array {
		try {
			$readable = $this->readableObject(target: $target);
		} catch (Throwable $e) {
			return $this->trials->objectNotFound(
				declaration: $declaration,
				target: new UnresolvedTrialObject(objectId: $target['objectId'], reason: $e->getMessage())
			);
		}

		return $this->trials->tryObject(
			declaration: $declaration,
			target: new TrialObject(object: $readable['object'], schema: $readable['schema'])
		);
	}//end objectTrial()

	/**
	 * Try the declaration against the sample payload of the request; no stored data is touched.
	 *
	 * @param array<string, mixed> $declaration The calculation declaration
	 *
	 * @return array<string, mixed> The trial result
	 */
	private function sampleTrial(array $declaration): array {
		$sample = $this->request->getParam('object', []);
		if (is_array($sample) === false) {
			$sample = [];
		}

		return $this->trials->trySample(declaration: $declaration, sample: $sample);
	}//end sampleTrial()

	/**
	 * Answer a trial result with the status it calls for.
	 *
	 * @param array<string, mixed> $result The trial result
	 *
	 * @return JSONResponse The result
	 */
	private function trialAnswer(array $result): JSONResponse {
		return new JSONResponse($result, $this->statusFor(result: $result));
	}//end trialAnswer()

	/**
	 * The stored object a trial names, when it names one in full.
	 *
	 * All three of `register`, `schema` and `objectId` are needed to address an
	 * object, so a request carrying only some of them is a sample trial, not a
	 * half-addressed object lookup.
	 *
	 * @return array{register: string, schema: string, objectId: string}|null The
	 *   addressed object, or null when the request does not name one.
	 */
	private function objectTarget(): ?array {
		$target = [
			'register' => $this->request->getParam('register'),
			'schema' => $this->request->getParam('schema'),
			'objectId' => $this->request->getParam('objectId'),
		];

		foreach ($target as $value) {
			if (is_string($value) === false || $value === '') {
				return null;
			}
		}

		return $target;

	}//end objectTarget()

	/**
	 * Resolve the named object as the caller is allowed to see it.
	 *
	 * Every lookup runs with RBAC and tenancy on. The object mapper adds the
	 * caller's read-permission and organisation filters to the query itself,
	 * so an object this caller may not read yields no row and a
	 * DoesNotExistException, exactly as an absent object does. Refusing with
	 * the same not-found keeps the trial from becoming an existence oracle.
	 *
	 * @param array{register: string, schema: string, objectId: string} $target The addressed object.
	 *
	 * @return array{object: ObjectEntity, schema: Schema} The object and its schema.
	 *
	 * @throws Throwable When the register, schema or object is absent or not readable by the caller.
	 */
	private function readableObject(array $target): array {
		$register = $this->schemaCatalog->registers()->find($target['register'], _rbac: true, _multitenancy: true);
		$schema = $this->schemaCatalog->schemas()->find($target['schema'], _rbac: true, _multitenancy: true);
		$object = $this->objectMapper->findInRegisterSchemaTable(
			identifier: $target['objectId'],
			register: $register,
			schema: $schema,
			_rbac: true,
			_multitenancy: true
		);

		return ['object' => $object, 'schema' => $schema];

	}//end readableObject()

	/**
	 * Map a trial result onto an HTTP status.
	 *
	 * A declaration the catalogue refuses is the caller's declaration, not a
	 * server fault, so it answers 422 with the code that refused it.
	 *
	 * @param array<string, mixed> $result The trial result.
	 *
	 * @return int The HTTP status code.
	 */
	private function statusFor(array $result): int {
		if (($result['ok'] ?? false) === true) {
			return 200;
		}

		if ((string)($result['error']['code'] ?? '') === 'calculation-trial-object-not-found') {
			return 404;
		}

		return 422;

	}//end statusFor()
}//end class
