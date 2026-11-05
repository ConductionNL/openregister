<?php

/**
 * OpenAPI Specification (OAS) Service
 *
 * This service generates OpenAPI Specification (OAS) documentation for registers and schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/actions/spec.md
 * @spec openspec/specs/deprecate-published-metadata/spec.md
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-14
 * @spec openspec/specs/mock-registers/spec.md#requirement-schema-compliance-with-adr-006
 * @spec openspec/specs/oas-generation/spec.md
 * @spec openspec/specs/oas-generation/spec.md
 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
 * @spec openspec/specs/oas-validation/spec.md#scenario-pagination-structure-follows-api-42
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\Oas\OasRbacAnnotator;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * OasService generates OpenAPI Specification documentation
 *
 * Service for generating OpenAPI Specification (OAS) documentation for registers and schemas.
 * Creates comprehensive API documentation including endpoints, schemas, parameters,
 * and examples based on register and schema definitions.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class OasService {
	/**
	 * Base path to OAS resources
	 *
	 * Path to base OpenAPI specification template file.
	 *
	 * @var string Base OAS resource file path
	 */
	private const OAS_RESOURCE_PATH = __DIR__ . '/Resources/BaseOas.json';

	/**
	 * The OpenAPI specification being built
	 *
	 * Array containing the complete OpenAPI specification structure.
	 *
	 * @var array<string, mixed> OpenAPI specification array
	 */
	private array $oas = [];

	/**
	 * Register mapper
	 *
	 * Handles database operations for register entities.
	 *
	 * @var RegisterMapperInterface Register mapper instance
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper
	 *
	 * Handles database operations for schema entities.
	 *
	 * @var SchemaMapperInterface Schema mapper instance
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * URL generator
	 *
	 * Generates absolute URLs for API endpoints in OAS documentation.
	 *
	 * @var IURLGenerator URL generator instance
	 */
	private readonly IURLGenerator $urlGenerator;

	/**
	 * Logger for validation messages (optional).
	 *
	 * @var LoggerInterface|null Logger or null
	 */
	private readonly ?LoggerInterface $logger;

	/**
	 * Validation report for the most recent createOas() invocation.
	 *
	 * @var OasValidationReport The current report
	 */
	private OasValidationReport $report;

	/**
	 * Standard HTTP methods an operation may use (NLGov API Design Rules 2.2.1).
	 * Old numbering: API-01 here meant /core/http-methods, API-03 meant /core/http-response-code.
	 * The rule table names GET, POST, PUT, PATCH and DELETE; HEAD and OPTIONS
	 * are standard RFC 9110 methods the rule's note allows, so documenting them
	 * is not a violation.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

	/**
	 * Path item keys that are not operations (OpenAPI 3.x Path Item Object).
	 *
	 * @var list<string>
	 */
	private const PATH_ITEM_FIELDS = ['parameters', 'summary', 'description', 'servers', '$ref'];

	/**
	 * NLGov-permitted HTTP response status codes (/core/http-response-code).
	 *
	 * @var list<string>
	 */
	private const ALLOWED_STATUS_CODES = ['200', '201', '204', '400', '401', '403', '404', '422', '500', 'default'];

	/**
	 * OpenAPI schema keywords a property definition keeps, in output order.
	 *
	 * @var list<string>
	 */
	private const PROPERTY_KEYWORDS = [
		'type',
		'format',
		'description',
		'example',
		'examples',
		'default',
		'enum',
		'const',
		'multipleOf',
		'maximum',
		'exclusiveMaximum',
		'minimum',
		'exclusiveMinimum',
		'maxLength',
		'minLength',
		'pattern',
		'maxItems',
		'minItems',
		'uniqueItems',
		'maxProperties',
		'minProperties',
		'required',
		'properties',
		'items',
		'additionalProperties',
		'allOf',
		'anyOf',
		'oneOf',
		'not',
		'$ref',
		'nullable',
		'readOnly',
		'writeOnly',
		'title',
	];

	/**
	 * The OpenAPI 3.1 types a property definition may declare.
	 *
	 * @var list<string>
	 */
	private const PROPERTY_TYPES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

	/**
	 * The JSON body of every error response: the shared Error schema.
	 *
	 * @var array<string, mixed>
	 */
	private const ERROR_CONTENT = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];

	/**
	 * The tail of a collection response's description, after "List of <schema>".
	 *
	 * @var string
	 */
	private const COLLECTION_RESPONSE_NOTE = ' objects with pagination metadata. '
		. 'Send `Accept: application/ld+json` to receive a JSON-LD `@graph` '
		. 'document (pagination under `or:` terms); the default '
		. '`application/json` representation is unchanged (json-ld-output).';

	/**
	 * The 200 response of a JSON-LD `@context` document endpoint.
	 *
	 * @var array<string, mixed>
	 */
	private const CONTEXT_DOCUMENT_RESPONSE = [
		'description' => 'JSON-LD context document of the form `{"@context": {…}}`.',
		'content' => ['application/ld+json' => ['schema' => ['type' => 'object', 'properties' => ['@context' => ['type' => 'object']]]]],
	];

	/**
	 * The JSON-LD `@graph` response schema of a collection.
	 *
	 * @var array<string, mixed>
	 */
	private const JSON_LD_COLLECTION_SCHEMA = [
		'type' => 'object',
		'description' => 'JSON-LD @graph serialization of the collection.',
		'properties' => [
			'@context' => ['type' => 'string', 'format' => 'uri'],
			'@graph' => ['type' => 'array', 'items' => ['type' => 'object']],
			'or:total' => ['type' => 'integer'],
		],
	];

	/**
	 * The JSON-LD response schema of a single object.
	 *
	 * @var array<string, mixed>
	 */
	private const JSON_LD_OBJECT_SCHEMA = [
		'type' => 'object',
		'description' => 'JSON-LD serialization of the object.',
		'properties' => [
			'@context' => ['type' => 'string', 'format' => 'uri'],
			'@id' => ['type' => 'string', 'format' => 'uri'],
			'@type' => ['type' => 'string'],
		],
	];

	/**
	 * The query parameters every object operation accepts.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const COMMON_QUERY_PARAMETERS = [
		[
			'name' => '_extend',
			'in' => 'query',
			'required' => false,
			'description' => 'Comma-separated list of properties to extend.',
			'schema' => ['type' => 'string'],
			'example' => 'property1,property2,property3',
		],
		[
			'name' => '_filter',
			'in' => 'query',
			'required' => false,
			'description' => 'Comma-separated list of properties to include in the response. ',
			'schema' => ['type' => 'string'],
			'example' => 'id,name,description',
		],
		[
			'name' => '_unset',
			'in' => 'query',
			'required' => false,
			'description' => 'Comma-separated list of properties to remove from the response.',
			'schema' => ['type' => 'string'],
			'example' => 'internalField1,internalField2',
		],
	];

	/**
	 * The full-text search parameter of a collection operation.
	 *
	 * @var array<string, mixed>
	 */
	private const SEARCH_QUERY_PARAMETER = [
		'name' => '_search',
		'in' => 'query',
		'required' => false,
		'description' => 'Full-text search query to filter objects in the collection.',
		'schema' => ['type' => 'string'],
		'example' => 'search term',
	];

	/**
	 * What the RBAC declarations mean for the document.
	 *
	 * @var OasRbacAnnotator
	 */
	private OasRbacAnnotator $rbacAnnotator;

	/**
	 * The RBAC groups (createGroups, readGroups, updateGroups, deleteGroups) of the schema whose paths are being added.
	 *
	 * @var array<string, array>
	 */
	private array $pathRbac = [];

	/**
	 * The operationId prefix of the register whose paths are being added; empty for none.
	 *
	 * @var string
	 */
	private string $operationIdPrefix = '';

	/**
	 * Constructor for OasService
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper for database operations
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for database operations
	 * @param IURLGenerator $urlGenerator URL generator for absolute URLs
	 * @param LoggerInterface|null $logger PSR-3 logger for surfacing validation issues
	 * @param ?OasRequestValidator $metaValidator Optional validator for the vendored OAS 3.1 meta-schema check.
	 * @param PropertyRbacHandler|null $propertyRbac Withholds a property the caller may not read. Nullable and
	 *                                               last so no construction site shifts; absent, a governed
	 *                                               property is withheld, which is the safe direction.
	 */
	public function __construct(
		RegisterMapperInterface $registerMapper,
		SchemaMapperInterface $schemaMapper,
		IURLGenerator $urlGenerator,
		?LoggerInterface $logger = null,
		private readonly ?OasRequestValidator $metaValidator = null,
		// LAST AND NULLABLE so every existing construction keeps working. The
		// container always supplies it; null happens only in a hand-built test,
		// and then a GOVERNED property is withheld, which is the safe direction.
		private readonly ?PropertyRbacHandler $propertyRbac = null,
	) {
		$this->registerMapper = $registerMapper;
		$this->schemaMapper = $schemaMapper;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->report = new OasValidationReport();
		$this->rbacAnnotator = new OasRbacAnnotator(
			registerMapper: $registerMapper,
			logger: $logger,
			propertyRbac: $propertyRbac
		);
	}//end __construct()

	/**
	 * Returns the validation report from the most recent createOas() call.
	 *
	 * @return OasValidationReport The current report
	 */
	public function getLastValidationReport(): OasValidationReport {
		return $this->report;
	}//end getLastValidationReport()

	/**
	 * Create OpenAPI Specification for register(s)
	 *
	 * Generates complete OpenAPI Specification documentation for one or all registers.
	 * Includes all schemas associated with the register(s), generates endpoint definitions,
	 * and creates comprehensive API documentation.
	 *
	 * @param string|null $registerId Optional register ID to generate OAS for specific register.
	 *                                If null, generates OAS for all registers.
	 * @param bool $strict When true, throws OasValidationException if any validation
	 *                     error is detected. When false (default), errors are auto-
	 *                     corrected where possible and logged via the report.
	 *
	 * @return array<string, mixed> The complete OpenAPI specification array
	 *
	 * @throws \Exception When base OAS file cannot be read or parsed
	 * @throws OasValidationException In strict mode, when the generated OAS has validation errors
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 * @spec openspec/specs/oas-validation/spec.md#requirement-server-url-is-absolute
	 */
	public function createOas(?string $registerId = null, bool $strict = false): array {
		$this->report = new OasValidationReport();
		$this->oas = $this->getBaseOas();

		// Bypass RBAC and multi-tenancy: the document describes every register
		// and schema, whatever the current user's organisation.
		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		if ($registerId !== null) {
			$registers = [$this->registerMapper->find($registerId, _rbac: false, _multitenancy: false)];
		}

		$schemas = $this->schemasOfRegisters(registers: $registers);
		$this->anchorServerUrls();
		if ($registerId !== null) {
			$this->describeRegister(register: $registers[0]);
		}

		$this->addSchemaComponents(schemas: $schemas);
		$schemaRbacMap = $this->addRbacScopes(schemas: $schemas);

		// Generating for several registers prefixes operationIds with the
		// register title, so two registers sharing a schema stay unique.
		$operationIdPrefixes = ($registerId === null && count($registers) > 1);
		$this->oas['paths'] = [];
		foreach ($registers ?? [] as $register) {
			$this->addRegisterPaths(
				register: $register,
				context: ['schemas' => $schemas, 'rbac' => $schemaRbacMap, 'prefixed' => $operationIdPrefixes]
			);
		}

		$this->validateOasIntegrity();
		$this->logValidationIssues();

		// Strict mode refuses invalid output. The report is still available via
		// getLastValidationReport() so the caller can render it.
		if ($strict === true && $this->report->hasErrors() === true) {
			throw new OasValidationException(
				message: 'Generated OAS failed strict validation: ' . count($this->report->getErrors()) . ' error(s)',
				report: $this->report,
			);
		}

		return $this->oas;
	}//end createOas()

	/**
	 * The schemas the registers use, each loaded once, indexed by id.
	 *
	 * @param array $registers The registers.
	 *
	 * @return array<int|string, Schema> The schemas by id.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function schemasOfRegisters(array $registers): array {
		$schemaIds = [];
		foreach ($registers as $register) {
			$schemaIds = array_merge($schemaIds, $register->getSchemas());
		}

		$schemas = [];
		foreach ($this->schemaMapper->findMultiple(array_unique($schemaIds), _rbac: false, _multitenancy: false) as $schema) {
			$schemas[$schema->getId()] = $schema;
		}

		return $schemas;
	}//end schemasOfRegisters()

	/**
	 * Point the server and the OAuth flow URLs at this instance.
	 *
	 * The OAuth flow URLs ship relative in BaseOas.json, and the OAS 3.1
	 * meta-schema types them `format: uri` (absolute), so a document leaving
	 * them relative fails its own meta-schema check, and a client resolving
	 * them against the page lands on the wrong host. Read, rewrite and write
	 * back rather than taking a reference: psalm cannot analyse a reference
	 * into a property (UnsupportedPropertyReferenceUsage).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md#requirement-server-url-is-absolute
	 */
	private function anchorServerUrls(): void {
		$this->oas['servers'] = [
			[
				'url' => $this->urlGenerator->getAbsoluteURL('/apps/openregister/api'),
				'description' => 'OpenRegister API Server',
			],
		];

		$flow = ($this->oas['components']['securitySchemes']['oauth2']['flows']['authorizationCode'] ?? null);
		if (is_array($flow) === false) {
			return;
		}

		foreach (['authorizationUrl', 'tokenUrl', 'refreshUrl'] as $urlKey) {
			if (isset($flow[$urlKey]) === true && str_starts_with((string)$flow[$urlKey], '/') === true) {
				$flow[$urlKey] = $this->urlGenerator->getAbsoluteURL((string)$flow[$urlKey]);
			}
		}

		$this->oas['components']['securitySchemes']['oauth2']['flows']['authorizationCode'] = $flow;
	}//end anchorServerUrls()

	/**
	 * Title, version and description of a single-register document, keeping the base contact and license.
	 *
	 * @param object $register The register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function describeRegister(object $register): void {
		$description = $register->getDescription();
		if (empty($description) === true) {
			$description = 'API for ' . $register->getTitle() . ' register providing CRUD ';
			$description .= 'operations, filtering, and search capabilities.';
		}

		$this->oas['info'] = array_merge(
			$this->oas['info'],
			[
				'title' => $register->getTitle() . ' API',
				'version' => $register->getVersion(),
				'description' => $description,
			]
		);
	}//end describeRegister()

	/**
	 * Add each titled schema as a component, with a tag named by its title.
	 *
	 * @param array<int|string, Schema> $schemas The schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function addSchemaComponents(array $schemas): void {
		$this->oas['tags'] = [];
		foreach ($schemas as $schema) {
			$schemaTitle = $schema->getTitle();
			if (empty($schemaTitle) === true) {
				continue;
			}

			$schemaDefinition = $this->enrichSchema(schema: $schema);
			if (empty($schemaDefinition) === true) {
				continue;
			}

			$this->oas['components']['schemas'][$this->sanitizeSchemaName(title: $schemaTitle)] = $schemaDefinition;
			$this->oas['tags'][] = [
				'name' => $schemaTitle,
				'description' => $schema->getDescription() ?? 'Operations for ' . $schemaTitle,
			];
		}
	}//end addSchemaComponents()

	/**
	 * Declare an OAuth2 scope for every group the schemas grant, plus admin.
	 *
	 * @param array<int|string, Schema> $schemas The schemas.
	 *
	 * @return array<int|string, array> Each schema's groups per operation, by schema id.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function addRbacScopes(array $schemas): array {
		$schemaRbacMap = [];
		$allGroups = [];
		foreach ($schemas as $schemaId => $schema) {
			$rbac = $this->rbacAnnotator->extractSchemaGroups(schema: $schema);
			$schemaRbacMap[$schemaId] = $rbac;
			$allGroups = array_merge($allGroups, $rbac['createGroups'], $rbac['readGroups'], $rbac['updateGroups'], $rbac['deleteGroups']);
		}

		$allGroups[] = 'admin';
		$scopes = [];
		foreach (array_values(array_unique($allGroups)) as $group) {
			$scopes[$group] = $this->rbacAnnotator->getScopeDescription(group: $group);
		}

		$this->oas['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] = $scopes;
		return $schemaRbacMap;
	}//end addRbacScopes()

	/**
	 * Add the CRUD, extended and JSON-LD context paths for each loaded schema of a register.
	 *
	 * @param object                                             $register The register.
	 * @param array{schemas: array, rbac: array, prefixed: bool} $context  The loaded schemas, their groups,
	 *                                                                     and whether operationIds carry the register prefix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function addRegisterPaths(object $register, array $context): void {
		$this->operationIdPrefix = '';
		if ($context['prefixed'] === true) {
			$this->operationIdPrefix = $this->pascalCase(string: $register->getTitle());
		}

		$noGroups = ['createGroups' => [], 'readGroups' => [], 'updateGroups' => [], 'deleteGroups' => []];
		foreach ($register->getSchemas() ?? [] as $schemaId) {
			$schema = ($context['schemas'][$schemaId] ?? null);
			if ($schema === null) {
				continue;
			}

			$this->pathRbac = ($context['rbac'][$schemaId] ?? $noGroups);
			$this->addCrudPaths(register: $register, schema: $schema);
			$this->addExtendedPaths(register: $register, schema: $schema);
			$this->addJsonLdContextPaths(register: $register, schema: $schema);
		}
	}//end addRegisterPaths()

	/**
	 * Log every validation issue once at the appropriate severity level.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/actions/spec.md
	 */
	private function logValidationIssues(): void {
		if ($this->logger === null || $this->report->isEmpty() === true) {
			return;
		}

		foreach ($this->report->getIssues() as $issue) {
			$context = ['path' => $issue['path'], 'code' => $issue['code']];
			if ($issue['severity'] === OasValidationReport::SEVERITY_ERROR) {
				$this->logger->error('OAS validation: ' . $issue['message'], $context);
				continue;
			}

			$this->logger->warning('OAS validation: ' . $issue['message'], $context);
		}
	}//end logValidationIssues()

	/**
	 * Get the base OAS file as array
	 *
	 * @return array The base OAS array
	 *
	 * @throws \Exception When file cannot be read or parsed
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function getBaseOas(): array {
		$content = file_get_contents(self::OAS_RESOURCE_PATH);
		if ($content === false) {
			throw new Exception('Could not read base OAS file');
		}

		$oas = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Could not parse base OAS file: ' . json_last_error_msg());
		}

		return $oas;
	}//end getBaseOas()

	/**
	 * Extended endpoints that should be included in OAS generation
	 * This whitelist ensures only stable, public-facing endpoints are documented
	 *
	 * @var array<string>
	 */
	private const INCLUDED_EXTENDED_ENDPOINTS = [
		// Only include stable, public-facing endpoints.
		// 'audit-trails' - Internal audit functionality, not for public API.
		// 'files' - File management, may be too complex for basic API consumers.
		// 'lock' - Locking mechanism, typically used internally.
		// 'unlock' - Unlocking mechanism, typically used internally.
	];

	/**
	 * Enrich a schema with valid OpenAPI schema definitions
	 *
	 * This method includes legitimate API properties like @self but ensures
	 * property definitions conform to OpenAPI schema standards.
	 *
	 * @param object $schema The schema object
	 *
	 * @return array Enriched schema with type, x-tags, and properties.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function enrichSchema(object $schema): array {
		$schemaProperties = $schema->getProperties();

		// Start with core API properties.
		$cleanProperties = [
			'_self' => [
				'$ref' => '#/components/schemas/_self',
				'readOnly' => true,
				'description' => 'Object metadata including timestamps, ownership, and system information',
			],
			'id' => [
				'type' => 'string',
				'format' => 'uuid',
				'readOnly' => true,
				'example' => '123e4567-e89b-12d3-a456-426614174000',
				'description' => 'The unique identifier for the object.',
			],
		];

		// 🔴 A FIELD NAME IS INFORMATION, AND THE GOVERNED NAMES ARE THE ONES
		// WORTH PROTECTING. `onderzoek_integriteit`, `schuldhulpverlening`,
		// `bijzondere_bijstand`: the name alone says what category of fact is
		// held, and on a record about one person it says the fact is held about
		// them. A property carries an authorization block or a scope precisely
		// because it is sensitive, so the set of governed names is by
		// construction the set most worth not printing.
		//
		// The objection is that a schema is a contract. It is smaller than it
		// looks: the API NEVER returns a property this caller may not read, so
		// describing it promises a field that will never arrive. Leaving it out
		// makes the document MORE truthful, not less. It describes the API this
		// caller actually has.
		$withheld = 0;
		foreach ($schemaProperties ?? [] as $propertyName => $propertyDefinition) {
			if ($this->rbacAnnotator->mayDescribe(schema: $schema, property: (string)$propertyName) === false) {
				$withheld++;
				continue;
			}

			$cleanProperties[$propertyName] = $this->sanitizePropertyDefinition(propertyDefinition: $propertyDefinition);
		}

		$described = [
			'type' => 'object',
			'x-tags' => [$schema->getTitle()],
			'properties' => $cleanProperties,
		];

		// A `required` list naming a property this document does not describe is
		// not a contract anyone can satisfy: a generated client would fail
		// validation on a field it cannot even see.
		$required = $this->rbacAnnotator->describableRequired(schema: $schema, described: $cleanProperties);
		if ($required !== []) {
			$described['required'] = $required;
		}

		if ($withheld > 0) {
			// A COUNT, NEVER NAMES. Naming them here would be the leak with an
			// audit trail attached. Saying nothing would be worse in its own
			// way: an integrator reading four properties cannot tell whether
			// that is the whole schema or the part they are allowed to see, and
			// would build as though it were complete. The count says there is
			// more here and it is not yours, without saying what.
			$described['x-openregister-withheld-properties'] = $withheld;
		}

		return $described;
	}//end enrichSchema()

	/**
	 * Sanitize property definition to be valid OpenAPI schema
	 *
	 * This method ensures property definitions conform to OpenAPI 3.1 standards
	 * by removing invalid properties and normalizing the structure.
	 *
	 * @param mixed $propertyDefinition The property definition to sanitize
	 *
	 * @return (array[]|mixed|string)[] Valid OpenAPI property definition
	 *
	 * @psalm-return array{
	 *     title?: mixed,
	 *     writeOnly?: mixed,
	 *     readOnly?: mixed,
	 *     nullable?: mixed,
	 *     '$ref'?: mixed,
	 *     not?: mixed,
	 *     oneOf?: mixed,
	 *     anyOf?: mixed,
	 *     allOf?: mixed|non-empty-list<non-empty-array>,
	 *     additionalProperties?: mixed,
	 *     items?: mixed,
	 *     properties?: mixed,
	 *     required?: mixed,
	 *     minProperties?: mixed,
	 *     maxProperties?: mixed,
	 *     uniqueItems?: mixed,
	 *     minItems?: mixed,
	 *     maxItems?: mixed,
	 *     pattern?: mixed,
	 *     minLength?: mixed,
	 *     maxLength?: mixed,
	 *     exclusiveMinimum?: mixed,
	 *     minimum?: mixed,
	 *     exclusiveMaximum?: mixed,
	 *     maximum?: mixed,
	 *     multipleOf?: mixed,
	 *     const?: mixed,
	 *     enum?: mixed,
	 *     default?: mixed,
	 *     examples?: mixed,
	 *     example?: mixed,
	 *     description?: 'Property value'|mixed,
	 *     format?: mixed,
	 *     type?: 'string'|mixed
	 * }
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function sanitizePropertyDefinition($propertyDefinition): array {
		// If it's not an array, convert to basic string type.
		if (is_array($propertyDefinition) === false) {
			return [
				'type' => 'string',
				'description' => 'Property value',
			];
		}

		// Copy only valid OpenAPI schema keywords (strips internal fields like
		// objectConfiguration, inversedBy, authorization, defaultBehavior, etc.).
		$cleanDef = [];
		foreach (self::PROPERTY_KEYWORDS as $keyword) {
			if (($propertyDefinition[$keyword] ?? null) !== null) {
				$cleanDef[$keyword] = $propertyDefinition[$keyword];
			}
		}

		$cleanDef = $this->sanitizeNestedDefinitions(cleanDef: $cleanDef);
		$cleanDef = $this->dropInvalidKeywords(cleanDef: $cleanDef);
		$cleanDef = $this->normalisePropertyType(cleanDef: $cleanDef);

		// Ensure we have at least a type and a description.
		if (isset($cleanDef['$ref']) === false) {
			$cleanDef['type'] ??= 'string';
			$cleanDef['description'] ??= 'Property value';
		}

		return $cleanDef;
	}//end sanitizePropertyDefinition()

	/**
	 * Recursively sanitize the nested definitions: items, properties and the composition keywords.
	 *
	 * @param array<string, mixed> $cleanDef The definition with only OpenAPI keywords
	 *
	 * @return array<string, mixed> The definition with sanitized nested definitions
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function sanitizeNestedDefinitions(array $cleanDef): array {
		if (is_array($cleanDef['items'] ?? null) === true) {
			$cleanDef['items'] = $this->sanitizePropertyDefinition(propertyDefinition: $cleanDef['items']);
		}

		if (is_array($cleanDef['properties'] ?? null) === true) {
			foreach ($cleanDef['properties'] as $subPropName => $subPropDef) {
				$cleanDef['properties'][$subPropName] = $this->sanitizePropertyDefinition(propertyDefinition: $subPropDef);
			}
		}

		foreach (['oneOf', 'anyOf', 'allOf'] as $compositionKey) {
			if (is_array($cleanDef[$compositionKey] ?? null) === false) {
				continue;
			}

			foreach ($cleanDef[$compositionKey] as $idx => $item) {
				if (is_array($item) === true) {
					$cleanDef[$compositionKey][$idx] = $this->sanitizePropertyDefinition(propertyDefinition: $item);
				}
			}
		}

		return $cleanDef;
	}//end sanitizeNestedDefinitions()

	/**
	 * Remove keyword values that violate the OpenAPI spec.
	 *
	 * OneOf, anyOf and enum must be non-empty lists, and a property-level `required`
	 * must be a list (booleans leak from schema config).
	 *
	 * @param array<string, mixed> $cleanDef The definition with only OpenAPI keywords
	 *
	 * @return array<string, mixed> The definition without invalid keywords
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function dropInvalidKeywords(array $cleanDef): array {
		foreach (['oneOf', 'anyOf', 'enum'] as $listKeyword) {
			if (isset($cleanDef[$listKeyword]) === true
				&& (empty($cleanDef[$listKeyword]) === true || is_array($cleanDef[$listKeyword]) === false)
			) {
				unset($cleanDef[$listKeyword]);
			}
		}

		$cleanDef = $this->keepValidAllOfItems(cleanDef: $cleanDef);
		$cleanDef = $this->normalisePropertyRef(cleanDef: $cleanDef);

		if (isset($cleanDef['required']) === true && is_array($cleanDef['required']) === false) {
			unset($cleanDef['required']);
		}

		return $cleanDef;
	}//end dropInvalidKeywords()

	/**
	 * Keep only the non-empty object items of allOf, and drop allOf when none remain.
	 *
	 * @param array<string, mixed> $cleanDef The definition with only OpenAPI keywords
	 *
	 * @return array<string, mixed> The definition with a valid or no allOf
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function keepValidAllOfItems(array $cleanDef): array {
		if (isset($cleanDef['allOf']) === false) {
			return $cleanDef;
		}

		$validAllOfItems = [];
		if (is_array($cleanDef['allOf']) === true) {
			$validAllOfItems = array_values(
				array_filter(
					$cleanDef['allOf'],
					static fn ($item): bool => is_array($item) === true && empty($item) === false
				)
			);
		}

		unset($cleanDef['allOf']);
		if (empty($validAllOfItems) === false) {
			$cleanDef['allOf'] = $validAllOfItems;
		}

		return $cleanDef;
	}//end keepValidAllOfItems()

	/**
	 * Drop a $ref that is not a non-empty string and turn a bare $ref (e.g. "vestiging") into a component reference.
	 *
	 * @param array<string, mixed> $cleanDef The definition with only OpenAPI keywords
	 *
	 * @return array<string, mixed> The definition with a valid or no $ref
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function normalisePropertyRef(array $cleanDef): array {
		if (isset($cleanDef['$ref']) === false) {
			return $cleanDef;
		}

		if (empty($cleanDef['$ref']) === true || is_string($cleanDef['$ref']) === false) {
			unset($cleanDef['$ref']);
			return $cleanDef;
		}

		if (strpos($cleanDef['$ref'], '#/') !== 0) {
			$cleanDef['$ref'] = '#/components/schemas/' . $this->sanitizeSchemaName(title: $cleanDef['$ref']);
		}

		return $cleanDef;
	}//end normalisePropertyRef()

	/**
	 * Map an unknown `type` to string and make `items` a JSON Schema object.
	 *
	 * A list of items is replaced by its first element, and an array type always gets items.
	 *
	 * @param array<string, mixed> $cleanDef The definition without invalid keywords
	 *
	 * @return array<string, mixed> The definition with a valid type and items
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function normalisePropertyType(array $cleanDef): array {
		if (is_string($cleanDef['type'] ?? null) === true
			&& in_array($cleanDef['type'], self::PROPERTY_TYPES, true) === false
		) {
			$cleanDef['type'] = 'string';
		}

		if (isset($cleanDef['items']) === true) {
			if (is_array($cleanDef['items']) === true && array_is_list($cleanDef['items']) === true) {
				// Sequential array (list) is not valid: use the first element or the default.
				$cleanDef['items'] = ($cleanDef['items'][0] ?? null);
			}

			if (is_array($cleanDef['items']) === false || empty($cleanDef['items']) === true) {
				$cleanDef['items'] = ['type' => 'string'];
			}
		}

		if (($cleanDef['type'] ?? null) === 'array' && isset($cleanDef['items']) === false) {
			$cleanDef['items'] = ['type' => 'string'];
		}

		return $cleanDef;
	}//end normalisePropertyType()

	/**
	 * Add CRUD paths for a schema.
	 *
	 * @param object $register The register object
	 * @param object $schema The schema object
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function addCrudPaths(object $register, object $schema): void {
		$basePath = $this->basePathOf(register: $register, schema: $schema);
		$this->oas['paths'][$basePath] = [
			'get' => $this->finishOperation(operation: $this->createGetCollectionOperation(schema: $schema), groupsKey: 'readGroups'),
			'post' => $this->finishOperation(operation: $this->createPostOperation(schema: $schema), groupsKey: 'createGroups'),
		];
		$this->oas['paths'][$basePath . '/{id}'] = [
			'get' => $this->finishOperation(operation: $this->createGetOperation(schema: $schema), groupsKey: 'readGroups'),
			'put' => $this->finishOperation(operation: $this->createPutOperation(schema: $schema), groupsKey: 'updateGroups'),
			'patch' => $this->finishOperation(operation: $this->createPatchOperation(schema: $schema), groupsKey: 'updateGroups'),
			'delete' => $this->finishOperation(operation: $this->createDeleteOperation(schema: $schema), groupsKey: 'deleteGroups'),
		];
	}//end addCrudPaths()

	/**
	 * The operation with the register's operationId prefix and the schema's groups under the given key applied.
	 *
	 * @param array  $operation The OpenAPI operation.
	 * @param string $groupsKey The RBAC key whose groups may call it.
	 *
	 * @return array The finished operation.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function finishOperation(array $operation, string $groupsKey): array {
		if ($this->operationIdPrefix !== '') {
			$operation['operationId'] = $this->operationIdPrefix . $operation['operationId'];
		}

		$this->rbacAnnotator->applyRbacToOperation(operation: $operation, groups: $this->pathRbac[$groupsKey] ?? []);
		return $operation;
	}//end finishOperation()

	/**
	 * The `/objects/{register}/{schema}` base path of a register/schema pair.
	 *
	 * @param object $register The register object.
	 * @param object $schema   The schema object.
	 *
	 * @return string The base path.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function basePathOf(object $register, object $schema): string {
		return '/objects/' . $this->slugOf(entity: $register) . '/' . $this->slugOf(entity: $schema);
	}//end basePathOf()

	/**
	 * The slug of a register or schema; its slugified title when it has none.
	 *
	 * @param object $entity The register or schema.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function slugOf(object $entity): string {
		$fallback = $this->slugify(string: $entity->getTitle());
		$slug     = $entity->getSlug();
		if ($slug === null || $slug === '') {
			return $fallback;
		}

		return $slug;
	}//end slugOf()

	/**
	 * Add the JSON-LD `@context` document endpoints for a register/schema
	 * pair (json-ld-output). These are the dereferenceable URLs that object
	 * JSON-LD serializations reference in their `@context`.
	 *
	 * @param object $register The register object.
	 * @param object $schema The schema object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function addJsonLdContextPaths(object $register, object $schema): void {
		$registerSlug = $this->slugOf(entity: $register);
		$schemaSlug   = $this->slugOf(entity: $schema);
		$operationId  = 'getContext' . $this->pascalCase(string: $registerSlug);
		$responses    = ['200' => self::CONTEXT_DOCUMENT_RESPONSE, '404' => $this->errorResponse(description: 'Register or schema not found')];

		// Register-wide context document (idempotent across schemas of a register).
		$this->oas['paths']['/contexts/' . $registerSlug]['get'] = [
			'summary' => 'JSON-LD context for register ' . $registerSlug,
			'operationId' => $operationId,
			'tags' => ['JSON-LD'],
			'description' => 'Dereferenceable register-wide JSON-LD @context document.',
			'responses' => $responses,
		];

		// Per-schema context document.
		$this->oas['paths']['/contexts/' . $registerSlug . '/' . $schemaSlug]['get'] = [
			'summary' => 'JSON-LD context for ' . $schemaSlug,
			'operationId' => $operationId . $this->pascalCase(string: $schemaSlug),
			'tags' => ['JSON-LD'],
			'description' => 'Dereferenceable per-schema JSON-LD @context document.',
			'responses' => $responses,
		];
	}//end addJsonLdContextPaths()

	/**
	 * Add extended paths for a schema using whitelist approach
	 *
	 * Only adds endpoints that are explicitly whitelisted in INCLUDED_EXTENDED_ENDPOINTS.
	 * This prevents internal/complex endpoints from being exposed in the public API spec.
	 *
	 * @param object $register The register object
	 * @param object $schema The schema object
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function addExtendedPaths(object $register, object $schema): void {
		$basePath = $this->basePathOf(register: $register, schema: $schema);
		foreach (self::INCLUDED_EXTENDED_ENDPOINTS ?? [] as $endpoint) {
			$pathItem = $this->extendedPathItem(endpoint: $endpoint, schema: $schema);
			if ($pathItem !== null) {
				$this->oas['paths'][$basePath . '/{id}/' . $endpoint] = $pathItem;
			}
		}

		// Note: By default, NO extended endpoints are included.
		// To include them, add them to INCLUDED_EXTENDED_ENDPOINTS constant.
		// This ensures a clean, minimal API specification focused on core CRUD operations.
	}//end addExtendedPaths()

	/**
	 * The path item of an extended endpoint (audit-trails, files, lock, unlock); null for any other.
	 *
	 * @param string $endpoint The endpoint name, also its path segment.
	 * @param object $schema   The schema object.
	 *
	 * @return array|null The path item.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function extendedPathItem(string $endpoint, object $schema): ?array {
		return match ($endpoint) {
			'audit-trails' => ['get' => $this->createLogsOperation(schema: $schema)],
			'files' => ['get' => $this->createGetFilesOperation(schema: $schema), 'post' => $this->createPostFileOperation(schema: $schema)],
			'lock' => ['post' => $this->createLockOperation(schema: $schema)],
			'unlock' => ['post' => $this->createUnlockOperation(schema: $schema)],
			default => null,
		};
	}//end extendedPathItem()

	/**
	 * Create common query parameters for object operations.
	 *
	 * @param bool        $isCollection Whether this is for a collection endpoint
	 * @param object|null $schema       The schema object for generating dynamic filter parameters
	 *                                  (only used for collection endpoints)
	 *
	 * @return array List of query parameter definitions for OpenAPI spec.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flag controls collection vs single item parameters
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createCommonQueryParameters(bool $isCollection = false, ?object $schema = null): array {
		if ($isCollection === false) {
			return self::COMMON_QUERY_PARAMETERS;
		}

		$parameters = [...self::COMMON_QUERY_PARAMETERS, self::SEARCH_QUERY_PARAMETER];
		if ($schema === null) {
			return $parameters;
		}

		foreach ($schema->getProperties() ?? [] as $propertyName => $propertyDefinition) {
			// Metadata properties are skipped, and id is already a path parameter.
			if (str_starts_with($propertyName, '@') === true || $propertyName === 'id') {
				continue;
			}

			$parameters[] = $this->propertyFilterParameter(propertyName: $propertyName, propertyDefinition: $propertyDefinition);
		}

		return $parameters;
	}//end createCommonQueryParameters()

	/**
	 * The query parameter that filters a collection by one schema property.
	 *
	 * @param string $propertyName       The property name
	 * @param mixed  $propertyDefinition The property definition
	 *
	 * @return array<string, mixed> OpenAPI parameter object
	 */
	private function propertyFilterParameter(string $propertyName, $propertyDefinition): array {
		$propertyType = $this->getPropertyType(propertyDefinition: $propertyDefinition);
		$paramSchema = ['type' => $propertyType];

		// Array types require an items field.
		if ($propertyType === 'array') {
			$paramSchema['items'] = ['type' => 'string'];
		}

		return [
			'name' => $propertyName,
			'in' => 'query',
			'required' => false,
			'description' => 'Filter results by ' . $propertyName,
			'schema' => $paramSchema,
		];
	}//end propertyFilterParameter()

	/**
	 * Get OpenAPI type for a property definition
	 *
	 * @param mixed $propertyDefinition The property definition from the schema
	 *
	 * @return string The OpenAPI type for the property
	 *
	 * @spec openspec/specs/mock-registers/spec.md#requirement-schema-compliance-with-adr-006
	 */
	private function getPropertyType($propertyDefinition): string {
		$validTypes = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

		// If the property definition is an array, look for the type key.
		if (is_array($propertyDefinition) === true && (($propertyDefinition['type'] ?? null) !== null)) {
			$type = $propertyDefinition['type'];
			// Validate the type is a recognized OpenAPI type.
			if (in_array($type, $validTypes, true) === true) {
				return $type;
			}

			return 'string';
		}

		// If the property definition is a string, assume it's the type.
		if (is_string($propertyDefinition) === true) {
			// Map common types to OpenAPI types.
			$typeMap = [
				'int' => 'integer',
				'float' => 'number',
				'bool' => 'boolean',
				'string' => 'string',
				'array' => 'array',
				'object' => 'object',
			];

			return $typeMap[$propertyDefinition] ?? 'string';
		}

		// Default to string if type cannot be determined.
		return 'string';
	}//end getPropertyType()

	/**
	 * An error response: a description and the shared Error schema as its JSON body.
	 *
	 * @param string $description The response description
	 *
	 * @return array<string, mixed> OpenAPI response object
	 */
	private function errorResponse(string $description): array {
		return ['description' => $description, 'content' => self::ERROR_CONTENT];
	}//end errorResponse()

	/**
	 * The required `id` path parameter of a single-object operation.
	 *
	 * @param string $description The parameter description
	 *
	 * @return array<string, mixed> OpenAPI parameter object
	 */
	private function idPathParameter(string $description): array {
		return [
			'name' => 'id',
			'in' => 'path',
			'required' => true,
			'description' => $description,
			'schema' => ['type' => 'string', 'format' => 'uuid'],
		];
	}//end idPathParameter()

	/**
	 * A JSON content map whose schema references a schema component by its sanitized name.
	 *
	 * @param string $schemaName The schema title
	 *
	 * @return array<string, mixed> OpenAPI content map
	 */
	private function schemaRefContent(string $schemaName): array {
		$reference = '#/components/schemas/' . $this->sanitizeSchemaName(title: $schemaName);
		return ['application/json' => ['schema' => ['$ref' => $reference]]];
	}//end schemaRefContent()

	/**
	 * Create GET collection operation.
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for GET collection.
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
	 */
	private function createGetCollectionOperation(object $schema): array {
		// Ensure schema has a valid title before proceeding.
		$schemaTitle = $schema->getTitle();
		if (empty($schemaTitle) === true) {
			$schemaTitle = 'UnknownSchema';
		}

		return [
			'summary' => 'Get all ' . $schemaTitle . ' objects',
			'operationId' => 'getAll' . $this->pascalCase(string: $schemaTitle),
			'tags' => [$schemaTitle],
			'description' => 'Retrieve a list of all ' . $schemaTitle . ' objects',
			'parameters' => $this->createCommonQueryParameters(isCollection: true, schema: $schema),
			'responses' => [
				'200' => [
					'description' => 'List of ' . $schemaTitle . self::COLLECTION_RESPONSE_NOTE,
					'content' => $this->paginatedContent(schemaTitle: $schemaTitle),
				],
				'400' => $this->errorResponse(description: 'Invalid query parameters'),
			],
		];
	}//end createGetCollectionOperation()

	/**
	 * The JSON and JSON-LD content of a paginated collection of a schema's objects.
	 *
	 * @param string $schemaTitle The schema title.
	 *
	 * @return array<string, mixed> OpenAPI content map
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
	 */
	private function paginatedContent(string $schemaTitle): array {
		$items = ['$ref' => '#/components/schemas/' . $this->sanitizeSchemaName(title: $schemaTitle)];
		$page  = ['type' => 'object', 'properties' => ['results' => ['type' => 'array', 'items' => $items]]];
		return [
			'application/json' => ['schema' => ['allOf' => [['$ref' => '#/components/schemas/PaginatedResponse'], $page]]],
			'application/ld+json' => ['schema' => self::JSON_LD_COLLECTION_SCHEMA],
		];
	}//end paginatedContent()

	/**
	 * Create GET operation.
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for GET single item.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createGetOperation(object $schema): array {
		// Get schema name for components reference.
		$schemaName = 'UnknownSchema';
		if (($schema->getTitle() !== null) === true && ($schema->getTitle() !== '') === true) {
			$schemaName = $schema->getTitle();
		}

		return [
			'summary' => 'Get a ' . $schema->getTitle() . ' object by ID',
			'operationId' => 'get' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Retrieve a specific ' . $schema->getTitle() . ' object by its unique identifier',
			'parameters' => array_merge(
				[
					$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object'),
				],
				$this->createCommonQueryParameters()
			),
			'responses' => [
				'200' => [
					'description' => $schema->getTitle() . ' found. Send '
						. '`Accept: application/ld+json` to receive the JSON-LD '
						. 'representation (`@context`/`@id`/`@type`); the default '
						. '`application/json` representation is unchanged (json-ld-output).',
					'content' => [
						'application/json' => [
							'schema' => [
								'$ref' => '#/components/schemas/' . $this->sanitizeSchemaName(title: $schemaName),
							],
						],
						'application/ld+json' => ['schema' => self::JSON_LD_OBJECT_SCHEMA],
					],
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
			],
		];
	}//end createGetOperation()

	/**
	 * Create PUT operation
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for PUT.
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
	 */
	private function createPutOperation(object $schema): array {
		// Determine schema name for use in schema references.
		$schemaName = 'UnknownSchema';
		if (($schema->getTitle() !== null && $schema->getTitle() !== '') === true) {
			$schemaName = $schema->getTitle();
		}

		return [
			'summary' => 'Update a ' . $schema->getTitle() . ' object',
			'operationId' => 'update' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Update an existing ' . $schema->getTitle() . ' object with the provided data',
			'parameters' => array_merge(
				[
					$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object to update'),
				],
				$this->createCommonQueryParameters()
			),
			'requestBody' => [
				'required' => true,
				'content' => $this->schemaRefContent(schemaName: $schemaName),
			],
			'responses' => [
				'200' => [
					'description' => $schema->getTitle() . ' updated successfully',
					'content' => $this->schemaRefContent(schemaName: $schemaName),
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
			],
		];
	}//end createPutOperation()

	/**
	 * Create PATCH operation (partial update, objects#patch).
	 *
	 * Same path, response and errors as PUT, but only the fields sent change,
	 * so the body is a JSON merge patch (RFC 7396) of the object and nothing in
	 * it is required.
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for PATCH.
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
	 */
	private function createPatchOperation(object $schema): array {
		$operation = $this->createPutOperation(schema: $schema);
		$title = $schema->getTitle();

		$operation['summary'] = 'Partially update a ' . $title . ' object';
		$operation['operationId'] = 'patch' . $this->pascalCase(string: $title);
		$operation['description'] = 'Change only the fields sent; fields left out keep their stored value';
		$operation['parameters'][0]['description'] = 'Unique identifier of the ' . $title . ' object to patch';
		$operation['requestBody'] = [
			'required' => true,
			'content' => [
				'application/merge-patch+json' => [
					'schema' => ['type' => 'object'],
				],
				'application/json' => [
					'schema' => ['type' => 'object'],
				],
			],
		];

		return $operation;
	}//end createPatchOperation()

	/**
	 * Create POST operation.
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for POST.
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
	 */
	private function createPostOperation(object $schema): array {
		// Determine schema name for use in schema references.
		$schemaName = 'UnknownSchema';
		if (($schema->getTitle() !== null && $schema->getTitle() !== '') === true) {
			$schemaName = $schema->getTitle();
		}

		$operation = [
			'summary' => 'Create a new ' . $schema->getTitle() . ' object',
			'operationId' => 'create' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Create a new ' . $schema->getTitle() . ' object with the provided data',
			'parameters' => $this->createCommonQueryParameters(),
			'requestBody' => [
				'required' => true,
				'content' => $this->schemaRefContent(schemaName: $schemaName),
			],
			'responses' => [
				'201' => [
					'description' => $schema->getTitle() . ' created successfully.',
					'content' => $this->schemaRefContent(schemaName: $schemaName),
				],
				'400' => $this->errorResponse(description: 'Invalid request body'),
			],
		];

		return $this->withUpsertOnKey(operation: $operation, schema: $schema);
	}//end createPostOperation()

	/**
	 * Document `_upsertOn` on a POST when the schema declares a key that refuses duplicates.
	 *
	 * The parameter's `enum` lists the schema's `refuse` uniqueness constraints
	 * (the legacy `unique` key named by its properties joined with `+`), the
	 * only names the upsert accepts, and the statuses only the upsert answers
	 * are added.
	 *
	 * @param array<string, mixed> $operation The POST operation.
	 * @param object               $schema    The schema object.
	 *
	 * @return array<string, mixed> The operation, with the upsert documented when it applies.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	private function withUpsertOnKey(array $operation, object $schema): array {
		if (method_exists($schema, 'getUpsertKeys') === false) {
			return $operation;
		}

		$keys = $schema->getUpsertKeys();

		if ($keys === []) {
			return $operation;
		}

		$operation['parameters'][] = [
			'name' => '_upsertOn',
			'in' => 'query',
			'required' => false,
			'description' => 'Create or update by a key this schema declares unique: the record holding the key is updated (200), '
				.'or created when none does (201). Needs a signed-in caller; cannot be combined with _failIfExists.',
			'schema' => ['type' => 'string', 'enum' => $keys],
		];

		$error = self::ERROR_CONTENT;
		$operation['responses']['200'] = [
			'description' => 'Upsert: the record holding the key was updated.',
			'content' => $operation['responses']['201']['content'],
		];
		$operation['responses']['401'] = ['description' => 'Upsert: the caller is not signed in.', 'content' => $error];
		$operation['responses']['409'] = [
			'description' => 'Upsert: more than one record holds the key (the matches are listed), or a record you cannot change holds it.',
			'content' => $error,
		];
		$operation['responses']['503'] = [
			'description' => 'Upsert: the key could not be looked up or locked; nothing was written. Retry after the Retry-After seconds.',
			'content' => $error,
		];

		return $operation;
	}//end withUpsertOnKey()

	/**
	 * Create DELETE operation
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for DELETE.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createDeleteOperation(object $schema): array {
		return [
			'summary' => 'Delete a ' . $schema->getTitle() . ' object',
			'operationId' => 'delete' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Delete a specific ' . $schema->getTitle() . ' object by its unique identifier',
			'parameters' => [
				$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object to delete'),
			],
			'responses' => [
				'204' => [
					'description' => $schema->getTitle() . ' deleted successfully',
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
			],
		];
	}//end createDeleteOperation()

	/**
	 * Create logs operation
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for logs endpoint.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createLogsOperation(object $schema): array {
		return [
			'summary' => 'Get audit logs for a ' . $schema->getTitle() . ' object',
			'operationId' => 'getLogs' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Retrieve the audit trail for a specific ' . $schema->getTitle() . ' object',
			'parameters' => [
				$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object'),
			],
			'responses' => [
				'200' => [
					'description' => 'Audit logs retrieved successfully',
					'content' => [
						'application/json' => [
							'schema' => [
								'type' => 'array',
								'items' => [
									'$ref' => '#/components/schemas/AuditTrail',
								],
							],
						],
					],
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
			],
		];
	}//end createLogsOperation()

	/**
	 * Create get files operation
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for get files endpoint.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createGetFilesOperation(object $schema): array {
		return [
			'summary' => 'Get files for a ' . $schema->getTitle() . ' object',
			'operationId' => 'getFiles' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Retrieve all files associated with a specific ' . $schema->getTitle() . ' object',
			'parameters' => [
				$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object'),
			],
			'responses' => [
				'200' => [
					'description' => 'Files retrieved successfully',
					'content' => [
						'application/json' => [
							'schema' => [
								'type' => 'array',
								'items' => [
									'$ref' => '#/components/schemas/File',
								],
							],
						],
					],
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
			],
		];
	}//end createGetFilesOperation()

	/**
	 * Create post file operation
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for post file endpoint.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createPostFileOperation(object $schema): array {
		return [
			'summary' => 'Upload a file for a ' . $schema->getTitle() . ' object',
			'operationId' => 'uploadFile' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Upload a new file and associate it with a specific ' . $schema->getTitle() . ' object',
			'parameters' => [
				$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object'),
			],
			'requestBody' => [
				'required' => true,
				'content' => [
					'multipart/form-data' => [
						'schema' => [
							'type' => 'object',
							'properties' => [
								'file' => [
									'type' => 'string',
									'format' => 'binary',
									'description' => 'The file to upload',
								],
							],
						],
					],
				],
			],
			'responses' => [
				'201' => [
					'description' => 'File uploaded successfully',
					'content' => [
						'application/json' => [
							'schema' => [
								'$ref' => '#/components/schemas/File',
							],
						],
					],
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
			],
		];
	}//end createPostFileOperation()

	/**
	 * Create lock operation
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for lock endpoint.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createLockOperation(object $schema): array {
		return [
			'summary' => 'Lock a ' . $schema->getTitle() . ' object',
			'operationId' => 'lock' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Lock a specific ' . $schema->getTitle() . ' object to prevent concurrent modifications',
			'parameters' => [
				$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object to lock'),
			],
			'responses' => [
				'200' => [
					'description' => 'Object locked successfully',
					'content' => [
						'application/json' => [
							'schema' => [
								'$ref' => '#/components/schemas/Lock',
							],
						],
					],
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
				'409' => [
					'description' => 'Object is already locked',
				],
			],
		];
	}//end createLockOperation()

	/**
	 * Create unlock operation
	 *
	 * @param object $schema The schema object
	 *
	 * @return array OpenAPI operation definition for unlock endpoint.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function createUnlockOperation(object $schema): array {
		return [
			'summary' => 'Unlock a ' . $schema->getTitle() . ' object',
			'operationId' => 'unlock' . $this->pascalCase(string: $schema->getTitle()),
			'tags' => [$schema->getTitle()],
			'description' => 'Remove the lock from a specific ' . $schema->getTitle() . ' object',
			'parameters' => [
				$this->idPathParameter(description: 'Unique identifier of the ' . $schema->getTitle() . ' object to unlock'),
			],
			'responses' => [
				'200' => [
					'description' => 'Object unlocked successfully',
				],
				'404' => $this->errorResponse(description: $schema->getTitle() . ' not found'),
				'409' => [
					'description' => 'Object is not locked or locked by another user',
				],
			],
		];
	}//end createUnlockOperation()

	/**
	 * Convert string to slug
	 *
	 * @param string $string The string to convert
	 *
	 * @return string The slugified string
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function slugify(string $string): string {
		return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
	}//end slugify()

	/**
	 * Convert string to PascalCase
	 *
	 * @param string $string The string to convert
	 *
	 * @return string The PascalCase string
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-14
	 */
	private function pascalCase(string $string): string {
		return str_replace(' ', '', ucwords(str_replace('-', ' ', $this->slugify(string: $string))));
	}//end pascalCase()

	/**
	 * Sanitize schema names to be OpenAPI compliant
	 *
	 * OpenAPI schema names must match pattern ^[a-zA-Z0-9._-]+$
	 * This method converts titles with spaces and special characters to valid schema names.
	 *
	 * @param string|null $title The schema title to sanitize
	 *
	 * @return string The sanitized schema name
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
	 */
	private function sanitizeSchemaName(?string $title): string {
		// Handle null or empty titles.
		if (empty($title) === true) {
			return 'UnknownSchema';
		}

		// Replace spaces and invalid characters with underscores.
		$sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title);

		// Remove multiple consecutive underscores.
		$sanitized = preg_replace('/_+/', '_', $sanitized);

		// Remove leading/trailing underscores.
		$sanitized = trim($sanitized, '_');

		// Handle edge case where sanitization results in empty string.
		if (empty($sanitized) === true) {
			return 'UnknownSchema';
		}

		// Ensure it starts with a letter (prepend 'Schema_' if it starts with number).
		if (preg_match('/^[0-9]/', $sanitized) === true) {
			$sanitized = 'Schema_' . $sanitized;
		}

		return $sanitized;
	}//end sanitizeSchemaName()

	/**
	 * Validate OpenAPI specification integrity
	 *
	 * This method checks for common issues that could cause ReDoc or other
	 * OpenAPI tools to fail when parsing the specification.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple nested loops and conditional checks for validating
	 *                                               paths, responses, and schemas
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function validateOasIntegrity(): void {
		$this->validateComponentReferences();
		$this->validateResponseReferences();
		$this->validateServerUrls();
		$this->validateOperationIdUniqueness();
		$this->validateTagConsistency();
		$this->validateNlGovRules();

		// Best-effort: skipped when no validator is wired or the meta file is not vendored.
		$this->validateAgainstMetaSchema();
	}//end validateOasIntegrity()

	/**
	 * Repair the $ref and allOf use inside every component schema.
	 *
	 * @return void
	 */
	private function validateComponentReferences(): void {
		if (isset($this->oas['components']['schemas']) === false) {
			return;
		}

		foreach (array_keys($this->oas['components']['schemas']) as $schemaName) {
			if (is_array($this->oas['components']['schemas'][$schemaName]) === true) {
				$this->validateSchemaReferences(
					schema: $this->oas['components']['schemas'][$schemaName],
					context: 'components.schemas.' . $schemaName
				);
			}
		}
	}//end validateComponentReferences()

	/**
	 * Report broken references in the JSON response schemas of every operation.
	 *
	 * The response schemas are checked on a copy: the document keeps them as they are.
	 *
	 * @return void
	 */
	private function validateResponseReferences(): void {
		if (isset($this->oas['paths']) === false) {
			return;
		}

		foreach (array_keys($this->oas['paths']) as $pathName) {
			foreach (array_keys($this->oas['paths'][$pathName]) as $method) {
				$this->validateOperationResponses(pathName: $pathName, method: $method);
			}
		}
	}//end validateResponseReferences()

	/**
	 * Report broken references in the JSON response schemas of one operation.
	 *
	 * @param int|string $pathName The path.
	 * @param int|string $method   The method key under the path.
	 *
	 * @return void
	 */
	private function validateOperationResponses(int|string $pathName, int|string $method): void {
		$operation = $this->oas['paths'][$pathName][$method];
		if (is_array($operation) === false || isset($operation['responses']) === false) {
			return;
		}

		foreach (array_keys($operation['responses']) as $statusCode) {
			$respSchema = ($operation['responses'][$statusCode]['content']['application/json']['schema'] ?? null);
			if ($respSchema !== null) {
				$this->validateSchemaReferences(
					schema: $respSchema,
					context: 'paths.' . $pathName . '.' . $method . '.responses.' . $statusCode
				);
			}
		}
	}//end validateOperationResponses()

	/**
	 * Validate the generated OAS document against the vendored OpenAPI 3.1
	 * meta-schema. Closes oas-validation issue #1378.
	 *
	 * @return void
	 */
	private function validateAgainstMetaSchema(): void {
		if ($this->metaValidator === null) {
			return;
		}

		$meta = $this->openApiMetaSchema();
		if ($meta === null) {
			return;
		}

		try {
			$this->reportMetaSchemaErrors(errors: $this->metaValidator->validate(body: $this->oas, schema: $meta));
		} catch (\Throwable $e) {
			// Validator errors MUST NOT block OAS generation — log and move on.
			$this->logger?->warning(
				'[OasService] meta-schema validation pass errored: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end validateAgainstMetaSchema()

	/**
	 * The vendored OpenAPI 3.1 meta-schema; null when it is missing or unreadable.
	 *
	 * @return array<string,mixed>|null The meta-schema.
	 */
	private function openApiMetaSchema(): ?array {
		$metaPath = __DIR__ . '/Resources/meta/openapi-3.1.0.json';
		if (file_exists($metaPath) === false) {
			return null;
		}

		$meta = json_decode((string)file_get_contents($metaPath), true);
		if (is_array($meta) === false) {
			return null;
		}

		return $meta;
	}//end openApiMetaSchema()

	/**
	 * Add each meta-schema violation to the report as an error.
	 *
	 * @param array<int,array{path:string,message:string}> $errors The violations.
	 *
	 * @return void
	 */
	private function reportMetaSchemaErrors(array $errors): void {
		foreach ($errors as $err) {
			$this->report->addError(
				path: $err['path'],
				message: 'OpenAPI 3.1 meta-schema violation: ' . $err['message'],
				code: 'meta-schema-violation'
			);
		}
	}//end reportMetaSchemaErrors()

	/**
	 * Verify every entry in `servers` uses an absolute URL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function validateServerUrls(): void {
		$servers = ($this->oas['servers'] ?? []);
		if (is_array($servers) === false) {
			return;
		}

		foreach ($servers as $idx => $server) {
			$url = (string)($server['url'] ?? '');
			if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
				$this->report->addError(
					path: 'servers.' . $idx . '.url',
					message: 'Server URL must be absolute (http:// or https://). Got: ' . $url,
					code: OasValidationReport::CODE_RELATIVE_SERVER_URL,
				);
			}
		}
	}//end validateServerUrls()

	/**
	 * Walk every operation and ensure operationIds are unique. Collisions are
	 * deduplicated in place by appending a numeric suffix (`_2`, `_3`, ...).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function validateOperationIdUniqueness(): void {
		if (isset($this->oas['paths']) === false || is_array($this->oas['paths']) === false) {
			return;
		}

		$seen = [];
		foreach (array_keys($this->oas['paths']) as $pathName) {
			if (is_array($this->oas['paths'][$pathName]) === true) {
				$this->dedupeOperationIds(pathName: $pathName, seen: $seen);
			}
		}
	}//end validateOperationIdUniqueness()

	/**
	 * Rename the operationIds under one path that an earlier operation already took.
	 *
	 * @param int|string        $pathName The path.
	 * @param array<string,int> $seen     Use count per operationId taken so far.
	 *
	 * @return void
	 */
	private function dedupeOperationIds(int|string $pathName, array &$seen): void {
		foreach ($this->oas['paths'][$pathName] as $method => &$operation) {
			$original = $this->operationIdOf(operation: $operation);
			if ($original === '') {
				continue;
			}

			$candidate = $this->claimOperationId(seen: $seen, operationId: $original);
			if ($candidate === $original) {
				continue;
			}

			$operation['operationId'] = $candidate;
			$this->report->addAutoCorrection(
				path: 'paths.' . $pathName . '.' . $method . '.operationId',
				message: 'Duplicate operationId "' . $original . '" auto-renamed to "' . $candidate . '".',
				code: OasValidationReport::CODE_DUPLICATE_OPERATION_ID,
			);
		}

		unset($operation);
	}//end dedupeOperationIds()

	/**
	 * Take an operationId: the id itself when free, otherwise the first free `_2`, `_3`, ... form.
	 *
	 * @param array<string,int> $seen        Use count per operationId taken so far.
	 * @param string            $operationId The wanted operationId.
	 *
	 * @return string The operationId taken.
	 */
	private function claimOperationId(array &$seen, string $operationId): string {
		if (isset($seen[$operationId]) === false) {
			$seen[$operationId] = 1;
			return $operationId;
		}

		do {
			$seen[$operationId]++;
			$candidate = $operationId . '_' . $seen[$operationId];
		} while (isset($seen[$candidate]) === true);

		$seen[$candidate] = 1;
		return $candidate;
	}//end claimOperationId()

	/**
	 * The operationId of an operation; '' when it is not an operation or has none.
	 *
	 * @param mixed $operation A path item entry.
	 *
	 * @return string The operationId.
	 */
	private function operationIdOf($operation): string {
		if (is_array($operation) === false || isset($operation['operationId']) === false) {
			return '';
		}

		return (string)$operation['operationId'];
	}//end operationIdOf()

	/**
	 * Cross-check declared `tags` against tag references in path operations.
	 *
	 * - Every tag referenced by an operation MUST exist in the top-level tags array
	 *   (orphan tag → auto-injected with a generated description, warning logged).
	 * - Every tag defined at the top level SHOULD be referenced by at least one
	 *   operation (unused tag → warning only, not auto-removed).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function validateTagConsistency(): void {
		$declaredTags = $this->declaredTags();
		$usedTags = $this->usedTags();
		$this->addOrphanTags(declaredTags: $declaredTags, usedTags: $usedTags);
		$this->reportUnusedTags(declaredTags: $declaredTags, usedTags: $usedTags);
	}//end validateTagConsistency()

	/**
	 * The top-level tag names, each with its index in the tags array.
	 *
	 * @return array<string,int|string> Index by tag name.
	 */
	private function declaredTags(): array {
		$declaredTags = [];
		foreach (($this->oas['tags'] ?? []) as $idx => $tagDef) {
			if (is_array($tagDef) === true && isset($tagDef['name']) === true) {
				$declaredTags[(string)$tagDef['name']] = $idx;
			}
		}

		return $declaredTags;
	}//end declaredTags()

	/**
	 * The tag names operations use, each with the paths of the operations using it.
	 *
	 * @return array<string,array<int,string>> Operation paths by tag name.
	 */
	private function usedTags(): array {
		$usedTags = [];
		foreach (($this->oas['paths'] ?? []) as $pathName => $pathItem) {
			if (is_array($pathItem) === false) {
				continue;
			}

			foreach ($pathItem as $method => $operation) {
				foreach ($this->operationTags(operation: $operation) as $tagName) {
					$usedTags[$tagName][] = 'paths.' . $pathName . '.' . $method;
				}
			}
		}

		return $usedTags;
	}//end usedTags()

	/**
	 * The non-empty string tags of an operation.
	 *
	 * @param mixed $operation A path item entry.
	 *
	 * @return array<int,string> The tag names.
	 */
	private function operationTags($operation): array {
		if (is_array($operation) === false || is_array($operation['tags'] ?? []) === false) {
			return [];
		}

		return array_filter(($operation['tags'] ?? []), fn ($tagName): bool => is_string($tagName) === true && $tagName !== '');
	}//end operationTags()

	/**
	 * Declare, with a generated description, every used tag that is not declared.
	 *
	 * @param array<string,int|string>         $declaredTags Index by declared tag name.
	 * @param array<string,array<int,string>> $usedTags     Operation paths by used tag name.
	 *
	 * @return void
	 */
	private function addOrphanTags(array $declaredTags, array $usedTags): void {
		foreach ($usedTags as $tagName => $usages) {
			if (isset($declaredTags[$tagName]) === true) {
				continue;
			}

			$this->oas['tags'][] = ['name' => $tagName, 'description' => 'Operations for ' . $tagName];
			$this->report->addAutoCorrection(
				path: $usages[0],
				message: 'Tag "' . $tagName . '" used by operations but not declared in top-level tags; auto-added.',
				code: OasValidationReport::CODE_ORPHAN_TAG,
			);
		}
	}//end addOrphanTags()

	/**
	 * Warn about every declared tag no operation uses.
	 *
	 * @param array<string,int|string>         $declaredTags Index by declared tag name.
	 * @param array<string,array<int,string>> $usedTags     Operation paths by used tag name.
	 *
	 * @return void
	 */
	private function reportUnusedTags(array $declaredTags, array $usedTags): void {
		foreach (array_diff_key($declaredTags, $usedTags) as $tagName => $idx) {
			$this->report->addWarning(
				path: 'tags[' . $idx . '].name',
				message: 'Top-level tag "' . $tagName . '" is declared but not referenced by any operation.',
				code: OasValidationReport::CODE_UNUSED_TAG,
			);
		}
	}//end reportUnusedTags()

	/**
	 * NLGov API Design Rules — narrow checks that are verifiable from the
	 * OAS document alone:
	 *
	 * - /core/http-methods (API-03 in the 1.0 numbering): only standard HTTP
	 *   methods on documented operations: GET, POST, PUT, PATCH, DELETE, and
	 *   the RFC 9110 methods HEAD and OPTIONS.
	 * - /core/http-response-code: only standard HTTP status codes on responses.
	 *
	 * @return void
	 */
	private function validateNlGovRules(): void {
		if (isset($this->oas['paths']) === false || is_array($this->oas['paths']) === false) {
			return;
		}

		foreach ($this->oas['paths'] as $pathName => $pathItem) {
			if (is_array($pathItem) === false) {
				continue;
			}

			foreach (array_keys($pathItem) as $method) {
				$this->checkNlGovOperation(pathName: $pathName, method: $method);
			}
		}
	}//end validateNlGovRules()

	/**
	 * Check one path item entry against the NLGov method and status code rules.
	 *
	 * @param int|string $pathName The path.
	 * @param int|string $method   The key under the path.
	 *
	 * @return void
	 */
	private function checkNlGovOperation(int|string $pathName, int|string $method): void {
		$methodKey = strtolower((string)$method);
		if (isset(array_flip(self::PATH_ITEM_FIELDS)[$methodKey]) === true) {
			return;
		}

		$path = 'paths.' . $pathName . '.' . $method;
		if (isset(array_flip(self::ALLOWED_HTTP_METHODS)[$methodKey]) === false) {
			$reason = 'violates NLGov /core/http-methods (only GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS allowed).';
			$message = sprintf('Non-standard HTTP method "%s" %s', (string)$method, $reason);
			$this->report->addError(path: $path, message: $message, code: OasValidationReport::CODE_INVALID_HTTP_METHOD);
			return;
		}

		$this->checkNlGovStatusCodes(path: $path, operation: $this->oas['paths'][$pathName][$method]);
	}//end checkNlGovOperation()

	/**
	 * Warn about every non-standard response status code of an operation.
	 *
	 * @param string $path      The operation's path in the document.
	 * @param mixed  $operation The operation.
	 *
	 * @return void
	 */
	private function checkNlGovStatusCodes(string $path, $operation): void {
		if (is_array($operation) === false) {
			return;
		}

		$allowedCodes = array_flip(self::ALLOWED_STATUS_CODES);
		foreach (array_keys(($operation['responses'] ?? [])) as $statusCode) {
			if (isset($allowedCodes[(string)$statusCode]) === false) {
				$this->report->addWarning(
					path: $path . '.responses.' . $statusCode,
					message: 'Non-standard HTTP status code "' . $statusCode . '" violates NLGov /core/http-response-code conventions.',
					code: OasValidationReport::CODE_INVALID_STATUS_CODE,
				);
			}
		}
	}//end checkNlGovStatusCodes()

	/**
	 * Validate schema references recursively
	 *
	 * @param array<string, mixed> $schema The schema to validate (passed by reference for modifications)
	 * @param string $context Context information for debugging
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-pagination-structure-follows-api-42
	 */
	private function validateSchemaReferences(array &$schema, string $context): void {
		$this->validateAllOf(schema: $schema, context: $context);
		$this->validateRef(schema: $schema, context: $context);
		$this->validateNestedReferences(schema: $schema, context: $context);
	}//end validateSchemaReferences()

	/**
	 * Keep only the allOf items that carry a $ref, a type or properties; drop an allOf left empty.
	 *
	 * @param array<string, mixed> $schema  The schema, repaired in place.
	 * @param string               $context The schema's path, for the report.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md
	 */
	private function validateAllOf(array &$schema, string $context): void {
		if (isset($schema['allOf']) === false) {
			return;
		}

		if (is_array($schema['allOf']) === false || empty($schema['allOf']) === true) {
			unset($schema['allOf']);
			return;
		}

		$validAllOfItems = array_values(
			array_filter($schema['allOf'], fn ($item): bool => is_array($item) === true && $this->isUsableAllOfItem(item: $item))
		);
		if (empty($validAllOfItems) === false) {
			$schema['allOf'] = $validAllOfItems;
			return;
		}

		unset($schema['allOf']);
		$this->report->addAutoCorrection(
			path: $context . '.allOf',
			message: 'allOf with no valid items was removed.',
			code: OasValidationReport::CODE_INVALID_ALLOF,
		);
	}//end validateAllOf()

	/**
	 * Whether an allOf item carries a non-empty string $ref, a type or properties.
	 *
	 * @param array<int|string, mixed> $item The allOf item.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/oas-validation/spec.md
	 */
	private function isUsableAllOfItem(array $item): bool {
		if (empty($item) === true) {
			return false;
		}

		if (empty($item['$ref']) === false && is_string($item['$ref']) === true) {
			return true;
		}

		return ($item['type'] ?? null) !== null || ($item['properties'] ?? null) !== null;
	}//end isUsableAllOfItem()

	/**
	 * Repair a $ref: drop an empty or non-string one, match a component name case-insensitively,
	 * and replace a dangling component reference with a string type.
	 *
	 * @param array<string, mixed> $schema  The schema, repaired in place.
	 * @param string               $context The schema's path, for the report.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md
	 */
	private function validateRef(array &$schema, string $context): void {
		if (($schema['$ref'] ?? null) === null) {
			return;
		}

		if (empty($schema['$ref']) === true || is_string($schema['$ref']) === false) {
			unset($schema['$ref']);
			return;
		}

		$refPath = str_replace('#/components/schemas/', '', $schema['$ref']);
		if (strpos($schema['$ref'], '#/components/schemas/') !== 0
			|| isset($this->oas['components']['schemas'][$refPath]) === true
		) {
			return;
		}

		$existingName = $this->componentNamedLike(refPath: $refPath);
		if ($existingName !== null) {
			$schema['$ref'] = '#/components/schemas/' . $existingName;
			return;
		}

		$this->replaceDanglingRef(schema: $schema, context: $context);
	}//end validateRef()

	/**
	 * Replace a $ref to a missing component with type=string and report it.
	 *
	 * @param array<string, mixed> $schema  The schema, repaired in place.
	 * @param string               $context The schema's path, for the report.
	 *
	 * @return void
	 */
	private function replaceDanglingRef(array &$schema, string $context): void {
		$refPath = str_replace('#/components/schemas/', '', $schema['$ref']);
		unset($schema['$ref']);
		if (isset($schema['type']) === false) {
			$schema['type'] = 'string';
			$schema['description'] = $schema['description'] ?? 'Reference to ' . $refPath;
		}

		$this->report->addError(
			path: $context . '.$ref',
			message: 'Dangling $ref to "#/components/schemas/' . $refPath . '"; substituted with type=string.',
			code: OasValidationReport::CODE_DANGLING_REF,
		);
	}//end replaceDanglingRef()

	/**
	 * The component schema whose name matches ignoring case, if any.
	 *
	 * @param string $refPath The referenced component name.
	 *
	 * @return string|null The existing name.
	 *
	 * @spec openspec/specs/oas-validation/spec.md
	 */
	private function componentNamedLike(string $refPath): ?string {
		foreach (array_keys($this->oas['components']['schemas'] ?? []) as $existingName) {
			if (strtolower($existingName) === strtolower($refPath)) {
				return $existingName;
			}
		}

		return null;
	}//end componentNamedLike()

	/**
	 * Validate the references in properties, items and oneOf/anyOf/allOf items, in place.
	 *
	 * @param array<string, mixed> $schema  The schema, repaired in place.
	 * @param string               $context The schema's path, for the report.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md
	 */
	private function validateNestedReferences(array &$schema, string $context): void {
		if (($schema['properties'] ?? null) !== null) {
			foreach ($schema['properties'] as $propName => &$property) {
				if (is_array($property) === true) {
					$this->validateSchemaReferences(schema: $property, context: "{$context}.properties.{$propName}");
				}
			}

			unset($property);
		}

		if (($schema['items'] ?? null) !== null && is_array($schema['items']) === true) {
			$this->validateSchemaReferences(schema: $schema['items'], context: "{$context}.items");
		}

		foreach (['oneOf', 'anyOf', 'allOf'] as $compositionKey) {
			if (is_array($schema[$compositionKey] ?? null) === true) {
				$this->validateCompositionItems(items: $schema[$compositionKey], context: "{$context}.{$compositionKey}");
			}
		}
	}//end validateNestedReferences()

	/**
	 * Repair the references inside each schema of a oneOf, anyOf or allOf list.
	 *
	 * @param array<int|string, mixed> $items   The list, repaired in place.
	 * @param string                   $context The list's path, for the report.
	 *
	 * @return void
	 */
	private function validateCompositionItems(array &$items, string $context): void {
		foreach ($items as $idx => &$compositionItem) {
			if (is_array($compositionItem) === true) {
				$this->validateSchemaReferences(schema: $compositionItem, context: "{$context}[{$idx}]");
			}
		}

		unset($compositionItem);
	}//end validateCompositionItems()

}//end class
