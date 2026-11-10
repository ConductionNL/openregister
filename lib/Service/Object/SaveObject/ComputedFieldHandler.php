<?php

/**
 * OpenRegister ComputedFieldHandler
 *
 * Handler for evaluating computed field expressions using Twig.
 * Supports save-time and read-time evaluation of Twig expressions
 * defined in schema property `computed` attributes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Twig\MappingTwigInstallerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Computed Field Handler
 *
 * Evaluates Twig expressions defined in schema property `computed` attributes.
 * Supports two evaluation modes:
 * - `save`: Computed at save time, value stored in database
 * - `read`: Computed at read time, value NOT stored in database
 *
 * Cross-reference lookups are supported via `_ref.propertyName.field` syntax,
 * which resolves referenced objects and extracts their properties.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects\SaveObject
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Handler requires Twig and mapper dependencies
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ComputedFieldHandler {
	/**
	 * Twig keywords and built-in helpers that are not dependencies of a computed expression.
	 *
	 * @var list<string>
	 */
	private const TWIG_RESERVED_WORDS = [
		'and',
		'or',
		'not',
		'in',
		'is',
		'as',
		'with',
		'true',
		'false',
		'null',
		'if',
		'else',
		'elseif',
		'endif',
		'for',
		'endfor',
		'set',
		'do',
		'block',
		'endblock',
		'date',
		'now',
		'min',
		'max',
		'range',
		'random',
		'length',
		'count',
	];

	/**
	 * The filters the Twig sandbox allows in computed expressions.
	 */
	private const SANDBOX_FILTERS = [
		'date',
		'date_modify',
		'upper',
		'lower',
		'trim',
		'length',
		'default',
		'number_format',
		'round',
		'abs',
		'split',
		'join',
		'slice',
		'first',
		'last',
		'replace',
		'format',
		'b64enc',
		'b64dec',
		'json_decode',
		'zgw_enum',
		'zgw_enum_reverse',
		'zgw_extract_uuid',
	];

	/**
	 * The functions the Twig sandbox allows in computed expressions.
	 */
	private const SANDBOX_FUNCTIONS = [
		'max',
		'min',
		'range',
		'generateUuid',
	];

	/**
	 * Twig environment instance for expression evaluation.
	 *
	 * @var Environment|null
	 */
	private ?Environment $twig = null;

	/**
	 * The fields in a dependency cycle, memoised per schema definition.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $cyclicBySchema = [];

	/**
	 * Constructor for ComputedFieldHandler.
	 *
	 * @param MagicObjectFinderInterface $objectMapper Mapper for fetching referenced objects.
	 * @param MappingTwigInstallerInterface $mappingTwig Adds the mapping filters, functions and their runtime.
	 * @param LoggerInterface $logger Logger for error and debug messages.
	 * @param CodedValueGuard|null $codedValues Rolls concept weights up into the declared score property.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $objectMapper,
		private readonly MappingTwigInstallerInterface $mappingTwig,
		private readonly LoggerInterface $logger,
		// The rolled-up concept score. Nullable with a null default so the
		// unit tests that build this handler positionally keep working; the
		// container resolves the real instance by type in production.
		private readonly ?CodedValueGuard $codedValues = null,
	) {
	}//end __construct()

	/**
	 * Get or create the sandboxed Twig environment.
	 *
	 * Creates a Twig environment with:
	 * - ArrayLoader for dynamic template strings
	 * - MappingExtension for custom filters/functions
	 * - SandboxExtension for security (restricts available tags, filters, functions)
	 *
	 * Autoescape is disabled. Twig's default autoescaping injects the `escape`
	 * filter into every `{{ var }}` output, but our sandbox SecurityPolicy
	 * doesn't allow that filter (and HTML escape isn't meaningful for computed
	 * property values that return strings/numbers/dates back into the object
	 * data). Without this flag, every Twig expression silently fails with
	 * `Filter "escape" is not allowed`.
	 *
	 * @return Environment The configured Twig environment
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function getTwig(): Environment {
		if ($this->twig !== null) {
			return $this->twig;
		}

		// Autoescape disabled: see the docblock.
		$this->twig = new Environment(new ArrayLoader(), ['autoescape' => false]);

		// Add the mapping extension for custom filters and functions.
		$this->mappingTwig->installOn(twig: $this->twig);

		// Configure sandbox for security.
		$this->twig->addExtension($this->sandbox());

		return $this->twig;
	}//end getTwig()

	/**
	 * The sandbox that restricts computed expressions to no tags, no methods
	 * or properties, and the allowed filters and functions.
	 *
	 * @return SandboxExtension The sandbox, enabled for every template.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function sandbox(): SandboxExtension {
		$policy = new SecurityPolicy(
			allowedTags: [],
			allowedFilters: self::SANDBOX_FILTERS,
			allowedMethods: [],
			allowedProperties: [],
			allowedFunctions: self::SANDBOX_FUNCTIONS
		);

		return new SandboxExtension($policy, sandboxed: true);
	}//end sandbox()

	/**
	 * Evaluate computed fields for the given evaluation mode.
	 *
	 * Iterates through schema properties, finds those with a `computed` attribute
	 * matching the specified evaluateOn mode, and evaluates their Twig expressions.
	 * A field that sits in a dependency cycle is not evaluated: it is set to null
	 * and the cycle is logged.
	 *
	 * @param array        $data The object data (used as Twig context).
	 * @param ComputedPass $pass The schema containing the property definitions and the mode: 'save' or 'read'.
	 *
	 * @return array The object data with computed field values added/updated.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-circular-dependency-detection
	 */
	public function evaluateComputedFields(array $data, ComputedPass $pass): array {
		$schema = $pass->schema;
		$cyclic = $this->cyclicFields(schema: $schema);

		foreach ($this->expressionsFor(schema: $schema, evaluateOn: $pass->evaluateOn) as $propertyName => $expression) {
			// A field in a cycle is not evaluated.
			$value = null;
			if (isset($cyclic[$propertyName]) === false) {
				$computed = new ComputedExpression(schema: $schema, propertyName: $propertyName, expression: $expression);
				$value = $this->evaluateExpression(computed: $computed, data: $data);
			}

			$data[$propertyName] = $value;
		}

		return $this->applyConceptScores(data: $data, schema: $schema);
	}//end evaluateComputedFields()

	/**
	 * The computed expressions a schema declares for one evaluation mode, by property name.
	 *
	 * @param Schema $schema The schema.
	 * @param string $evaluateOn The evaluation mode: 'save' or 'read'.
	 *
	 * @return array<string, string> The expressions.
	 */
	private function expressionsFor(Schema $schema, string $evaluateOn): array {
		$expressions = [];
		foreach (($schema->getProperties() ?? []) as $propertyName => $property) {
			if ($this->isComputedOn(property: $property, evaluateOn: $evaluateOn) === true && empty($property['computed']['expression']) === false) {
				$expressions[(string)$propertyName] = $property['computed']['expression'];
			}
		}

		return $expressions;
	}//end expressionsFor()

	/**
	 * The computed fields that sit in a dependency cycle, each cycle logged once.
	 *
	 * @param Schema $schema The schema.
	 *
	 * Memoised per schema definition within the request, so a list read does
	 * not re-analyse the expressions once per object.
	 *
	 * @return array<string, true> The fields in a cycle, by name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-circular-dependency-detection
	 */
	private function cyclicFields(Schema $schema): array {
		$key = $schema->getId() . ':' . md5((string)json_encode($schema->getProperties()));
		if (isset($this->cyclicBySchema[$key]) === false) {
			$this->cyclicBySchema[$key] = $this->findCyclicFields(schema: $schema);
		}

		return $this->cyclicBySchema[$key];
	}//end cyclicFields()

	/**
	 * Detect the cycles of a schema, log each, and name the fields in them.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, true> The fields in a cycle, by name.
	 */
	private function findCyclicFields(Schema $schema): array {
		$cyclic = [];
		foreach ($this->detectCircularDependencies(schema: $schema) as $cycle) {
			$this->logger->warning(
				message: '[ComputedFieldHandler] Circular dependency detected between computed fields; they are set to null: ' . implode(' -> ', $cycle),
				context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $schema->getId(), 'cycle' => $cycle]
			);
			$cyclic += array_fill_keys($cycle, true);
		}

		return $cyclic;
	}//end findCyclicFields()

	/**
	 * Write the rolled-up scores a schema's coded properties declare.
	 *
	 * A multi-valued coded property may declare `score.property`, and the
	 * weights of the concepts it holds are summed into that property. It runs
	 * beside the Twig computed fields rather than inside them because the
	 * inputs are not in the object at all: they are the concepts' own weights,
	 * which live in the vocabulary register.
	 *
	 * Both callers of this method matter. On SAVE the score is stored; on
	 * RENDER it is recomputed, so changing a concept's weight changes every
	 * score derived from it without rewriting a single object.
	 *
	 * @param array<string,mixed> $data The object data.
	 * @param Schema $schema The schema being evaluated.
	 *
	 * @return array<string,mixed> The data with the scores written.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	private function applyConceptScores(array $data, Schema $schema): array {
		foreach ($this->conceptScores(data: $data, schema: $schema) as $property => $score) {
			$data[$property] = $score;
		}

		return $data;
	}//end applyConceptScores()

	/**
	 * The rolled-up concept scores of an object; none without a coded-value guard or when the vocabulary
	 * cannot be read.
	 *
	 * @param array<string,mixed> $data   The object data.
	 * @param Schema              $schema The schema being evaluated.
	 *
	 * @return array<string,float> The scores, keyed by target property.
	 */
	private function conceptScores(array $data, Schema $schema): array {
		if ($this->codedValues === null) {
			return [];
		}

		try {
			return $this->codedValues->rolledUpScores(object: $data, schema: $schema);
		} catch (\Throwable $failure) {
			// A vocabulary that cannot be read must not become the reason an
			// object cannot be saved or rendered; the score is simply absent.
			$this->logger->warning(
				message: '[ComputedFieldHandler] Rolled-up concept score skipped: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $schema->getId()]
			);

			return [];
		}
	}//end conceptScores()

	/**
	 * Evaluate a single Twig expression with the given data context.
	 *
	 * Handles cross-reference resolution (via _ref prefix) and graceful error handling.
	 * On error, returns null and logs a warning.
	 *
	 * @param ComputedExpression $computed The property, its expression and the schema for cross-reference resolution.
	 * @param array              $data     The object data as Twig context.
	 *
	 * @return mixed The computed value, or null on error.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function evaluateExpression(ComputedExpression $computed, array $data): mixed {
		try {
			// Build the Twig context: object data + resolved cross-references.
			$context = $this->buildTwigContext(data: $data, schema: $computed->schema);
			$result = $this->render(computed: $computed, context: $context);

			// Attempt to cast numeric results to their appropriate types.
			return $this->castResult(result: $result);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: 'Computed field evaluation error: ' . $e->getMessage(),
				context: $this->failureContext(
					details: ['propertyName' => $computed->propertyName, 'expression' => $computed->expression, 'error' => $e->getMessage()]
				)
			);

			return null;
		}
	}//end evaluateExpression()

	/**
	 * The log context of a failed evaluation or lookup: the app name and location, then the details.
	 *
	 * @param array<string, mixed> $details The details.
	 *
	 * @return array<string, mixed>
	 */
	private function failureContext(array $details): array {
		return ['app' => 'openregister', 'file' => __FILE__, 'line' => __LINE__] + $details;
	}//end failureContext()

	/**
	 * Render an expression as a named template in the sandboxed environment, trimmed.
	 *
	 * @param ComputedExpression $computed The expression and its template name.
	 * @param array              $context  The Twig context.
	 *
	 * @return string The rendered, trimmed result.
	 */
	private function render(ComputedExpression $computed, array $context): string {
		$name = $computed->templateName();
		$twig = $this->getTwig();

		/*
		 * @var \Twig\Loader\ArrayLoader $loader
		 */

		$loader = $twig->getLoader();
		$loader->setTemplate($name, $computed->expression);

		return trim($twig->render($name, $context));
	}//end render()

	/**
	 * Build the Twig context from object data, including cross-reference lookups.
	 *
	 * Resolves properties referenced via `_ref` by fetching related objects
	 * and making their data available in the Twig context under the `_ref` key.
	 *
	 * References are resolved one level deep: a referenced object's own references are not followed.
	 *
	 * @param array $data The object data.
	 * @param Schema $schema The schema for identifying reference properties.
	 *
	 * @return array The Twig context with data and resolved references.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function buildTwigContext(array $data, Schema $schema): array {
		$context = $data;
		$refs = $this->resolveReferences(data: $data, schema: $schema);
		if (empty($refs) === false) {
			$context['_ref'] = $refs;
		}

		return $context;
	}//end buildTwigContext()

	/**
	 * Resolve cross-references for properties that contain object UUIDs.
	 *
	 * Looks at schema properties with `$ref` definitions and resolves the
	 * referenced objects, returning their data indexed by property name.
	 *
	 * @param array $data The object data containing reference UUIDs.
	 * @param Schema $schema The schema with property definitions.
	 *
	 * @return array Resolved reference data indexed by property name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveReferences(array $data, Schema $schema): array {
		$refs = [];
		foreach (($schema->getProperties() ?? []) as $propertyName => $property) {
			if ($this->isReferenceProperty(property: $property) === true) {
				$refs[$propertyName] = $this->referencedData(propertyName: $propertyName, refValue: $data[$propertyName] ?? null);
			}
		}

		return $refs;
	}//end resolveReferences()

	/**
	 * Whether a property is a reference: it has a `$ref`, or is a string with format 'uuid'.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return bool
	 */
	private function isReferenceProperty(mixed $property): bool {
		return isset($property['$ref']) === true
			|| (($property['type'] ?? '') === 'string' && ($property['format'] ?? '') === 'uuid');
	}//end isReferenceProperty()

	/**
	 * The data of the object a reference value names; [] for a value that is no non-blank string or names
	 * no readable object.
	 *
	 * The object is read without RBAC and tenancy: it only feeds the computed value.
	 *
	 * @param int|string $propertyName The reference property (for logging).
	 * @param mixed      $refValue     The reference value (a UUID).
	 *
	 * @return array
	 */
	private function referencedData(int|string $propertyName, mixed $refValue): array {
		if (is_string($refValue) === false || trim($refValue) === '') {
			return [];
		}

		try {
			return $this->objectMapper->find(identifier: $refValue, _rbac: false, _multitenancy: false)->getObject() ?? [];
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[ComputedFieldHandler] Failed to resolve reference',
				context: $this->failureContext(details: ['propertyName' => $propertyName, 'refValue' => $refValue, 'error' => $e->getMessage()])
			);

			return [];
		}
	}//end referencedData()

	/**
	 * Cast a string result to its appropriate PHP type.
	 *
	 * Converts numeric strings to int/float as appropriate.
	 * Returns the original string for non-numeric values.
	 *
	 * @param string $result The rendered template result.
	 *
	 * @return mixed The cast result.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function castResult(string $result): mixed {
		// A non-numeric result, including '', stays the string it is.
		if (is_numeric($result) === false) {
			return $result;
		}

		// An integer has no decimal point.
		if (str_contains($result, '.') === false) {
			return (int)$result;
		}

		return (float)$result;
	}//end castResult()

	/**
	 * Check if a schema has any computed properties.
	 *
	 * Utility method to quickly determine if computed field evaluation
	 * is needed for a given schema.
	 *
	 * @param Schema $schema The schema to check.
	 *
	 * @return bool True if the schema has at least one computed property.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasComputedProperties(Schema $schema): bool {
		foreach ($schema->getProperties() ?? [] as $property) {
			if ($this->isComputed(property: $property) === true) {
				return true;
			}
		}

		return false;
	}//end hasComputedProperties()

	/**
	 * Whether a property definition declares a computed block.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return bool True when `computed` is set and is an array.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isComputed(mixed $property): bool {
		return isset($property['computed']) === true && is_array($property['computed']) === true;
	}//end isComputed()

	/**
	 * Whether a property is computed in an evaluation mode.
	 *
	 * @param mixed $property The property definition.
	 * @param string $evaluateOn The evaluation mode: 'save' or 'read'.
	 *
	 * @return bool True when computed with that `evaluateOn` (default 'save').
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isComputedOn(mixed $property, string $evaluateOn): bool {
		return $this->isComputed(property: $property) === true && ($property['computed']['evaluateOn'] ?? 'save') === $evaluateOn;
	}//end isComputedOn()

	/**
	 * Get the names of computed properties for a given evaluation mode.
	 *
	 * @param Schema $schema The schema to inspect.
	 * @param string $evaluateOn The evaluation mode: 'save' or 'read'.
	 *
	 * @return array List of property names that are computed for the given mode.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getComputedPropertyNames(Schema $schema, string $evaluateOn = 'save'): array {
		$names = [];
		foreach ($schema->getProperties() ?? [] as $propertyName => $property) {
			if ($this->isComputedOn(property: $property, evaluateOn: $evaluateOn) === true) {
				$names[] = $propertyName;
			}
		}

		return $names;
	}//end getComputedPropertyNames()

	/**
	 * Detect circular dependencies between computed fields on a schema.
	 *
	 * Performs a static analysis of the Twig expressions to build a
	 * dependency graph (computed property -> set of property names it
	 * references) and walks the graph with DFS to surface cycles.
	 * Same-property self-references count as cycles of length 1.
	 *
	 * The detector is conservative: it only flags references between
	 * properties that are themselves computed. Non-computed inputs are
	 * inert leaves of the graph and never close a cycle.
	 *
	 * Returns an array of cycles; each cycle is the ordered list of
	 * property names traversed (e.g. `['a', 'b', 'a']`). An empty array
	 * means the schema is acyclic and safe to evaluate.
	 *
	 * Closes the computed-fields spec requirement
	 * "Circular Dependency Detection".
	 *
	 * @param Schema $schema The schema to analyse.
	 *
	 * @return array<int, array<int, string>> Detected cycles.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-2
	 */
	public function detectCircularDependencies(Schema $schema): array {
		$properties = $schema->getProperties() ?? [];
		$computedNames = $this->computedNames(properties: $properties);
		if ($computedNames === []) {
			return [];
		}

		$graph = $this->dependencyGraph(properties: $properties, computedNames: $computedNames);

		return (new ComputedFieldCycleWalk(graph: $graph))->cycles();
	}//end detectCircularDependencies()

	/**
	 * The names of the computed properties, in schema order.
	 *
	 * @param array $properties The schema properties.
	 *
	 * @return array<string, true> The computed property names, as keys.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-2
	 */
	private function computedNames(array $properties): array {
		$computedNames = [];
		foreach ($properties as $propertyName => $property) {
			if ($this->isComputed(property: $property) === true) {
				$computedNames[$propertyName] = true;
			}
		}

		return $computedNames;
	}//end computedNames()

	/**
	 * The dependency graph of the computed properties.
	 *
	 * @param array $properties The schema properties.
	 * @param array<string, true> $computedNames The computed property names.
	 *
	 * @return array<string, array<string>> Computed property => the computed properties it references.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-2
	 */
	private function dependencyGraph(array $properties, array $computedNames): array {
		$graph = [];
		foreach (array_keys($computedNames) as $propertyName) {
			$graph[$propertyName] = $this->computedEdges(
				expression: $properties[$propertyName]['computed']['expression'] ?? '',
				computedNames: $computedNames
			);
		}

		return $graph;
	}//end dependencyGraph()

	/**
	 * The distinct computed properties an expression references.
	 *
	 * Only edges that point to other computed properties can close a cycle:
	 * non-computed inputs are inert leaves.
	 *
	 * @param mixed $expression The Twig expression; a non-string or '' has no edges.
	 * @param array<string, true> $computedNames The computed property names.
	 *
	 * @return array<int, string> The referenced computed properties, in first-reference order.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-2
	 */
	private function computedEdges(mixed $expression, array $computedNames): array {
		if (is_string($expression) === false || $expression === '') {
			return [];
		}

		$edges = array_filter(
			$this->extractTwigVariables(expression: $expression),
			static fn (string $name): bool => isset($computedNames[$name]) === true
		);

		return array_values(array_unique($edges));
	}//end computedEdges()

	/**
	 * Extract candidate variable names from a Twig expression.
	 *
	 * The handler doesn't lean on a real Twig AST walker (which would
	 * require parsing every expression with a sandbox-bound environment
	 * just for static analysis). Instead it pulls identifiers out of
	 * `{{ ... }}` and `{% ... %}` blocks via a regex that catches the
	 * top-level word in dotted/array references — `foo`, `foo.bar`,
	 * `foo[0]`, `_ref.bar.x`. References under the `_ref.` prefix are
	 * cross-object lookups, not in-schema references, so they're
	 * stripped before the result is returned.
	 *
	 * @param string $expression The Twig expression source.
	 *
	 * @return array<int, string> Distinct top-level identifiers.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-1
	 */
	private function extractTwigVariables(string $expression): array {
		$body = $this->twigCodeBodies(expression: $expression);
		if ($body === '') {
			return [];
		}

		$identifiers = $this->twigIdentifiers(body: $body);

		// Strip cross-object reference prefix (`_ref.foo`) — those are
		// foreign-object lookups and not in-schema dependencies.
		unset($identifiers['_ref']);

		return array_keys($identifiers);
	}//end extractTwigVariables()

	/**
	 * The identifiers in Twig code that are not reserved words.
	 *
	 * Identifiers MUST start with a letter or underscore.
	 *
	 * @param string $body The Twig code.
	 *
	 * @return array<string, true> The identifiers, as keys, in first-seen order.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-1
	 */
	private function twigIdentifiers(string $body): array {
		$identifiers = [];
		if (preg_match_all('/(?<![\\w.])([A-Za-z_][A-Za-z0-9_]*)/', $body, $matches) > 0) {
			foreach ($matches[1] as $name) {
				if (in_array($name, self::TWIG_RESERVED_WORDS, true) === false) {
					$identifiers[$name] = true;
				}
			}
		}

		return $identifiers;
	}//end twigIdentifiers()

	/**
	 * The bodies of every `{{ ... }}` block, then every `{% ... %}` block,
	 * joined by spaces, so plain text in the template is not scanned as code.
	 *
	 * @param string $expression The Twig expression
	 *
	 * @return string The joined block bodies, or '' without any
	 */
	private function twigCodeBodies(string $expression): string {
		$found = preg_match_all('/\{\{(.+?)\}\}|\{%(.+?)%\}/s', $expression, $blocks);
		if ($found === false || $found === 0) {
			return '';
		}

		$notEmpty = static fn (string $body): bool => $body !== '';

		return implode(' ', array_merge(array_filter($blocks[1], $notEmpty), array_filter($blocks[2], $notEmpty)));
	}//end twigCodeBodies()
}//end class
