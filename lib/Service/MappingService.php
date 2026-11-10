<?php

/**
 * OpenRegister Mapping Service
 *
 * Service for executing data mappings using Twig templating and dot notation.
 * Provides data transformation capabilities between different formats.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Adbar\Dot;
use Exception;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Twig\MappingTwigInstaller;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TemplateWrapper;

/**
 * Service for executing data mappings
 *
 * Provides functionality to transform data from one format to another using
 * mapping configurations. Uses Twig templating for dynamic value transformations
 * and dot notation for nested array access.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    Mapping execution requires comprehensive handling
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)      $list parameter clearly indicates list processing mode
 */
class MappingService implements MappingServiceInterface {

	/**
	 * Casts that carry an argument: prefix as written => cast type.
	 *
	 * @var array<string, string>
	 */
	private const ARGUMENT_CASTS = [
		'unsetIfValue==' => 'unsetIfValue',
		'setNullIfValue==' => 'setNullIfValue',
		'countValue:' => 'countValue',
	];

	/**
	 * Casts that apply a PHP function to the value as it is.
	 *
	 * @var array<string, callable-string>
	 */
	private const VALUE_FUNCTION_CASTS = [
		'string' => 'strval',
		'int' => 'intval',
		'integer' => 'intval',
		'float' => 'floatval',
		'json' => 'json_encode',
	];

	/**
	 * Casts that apply a PHP function to the value as a string.
	 *
	 * @var array<string, callable-string>
	 */
	private const STRING_FUNCTION_CASTS = [
		'date' => 'date',
		'url' => 'urlencode',
		'urlDecode' => 'urldecode',
		'rawurl' => 'rawurlencode',
		'rawurlDecode' => 'rawurldecode',
		'html' => 'htmlentities',
		'htmlDecode' => 'html_entity_decode',
		'base64' => 'base64_encode',
		'base64Decode' => 'base64_decode',
	];

	/**
	 * Twig templating environment
	 *
	 * @var Environment
	 */
	private Environment $twig;

	/**
	 * In-memory cache for compiled Twig templates, keyed by template string hash
	 *
	 * @var array<string, TemplateWrapper>
	 */
	private array $templateCache = [];

	/**
	 * Distributed cache for mapping entity lookups (APCu/Redis)
	 *
	 * @var ICache|null
	 */
	private ?ICache $mappingCache = null;

	/**
	 * Cache TTL for mapping entities in seconds (5 minutes)
	 *
	 * @var int
	 */
	private const MAPPING_CACHE_TTL = 300;

	/**
	 * Cache key prefix for mapping entities
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'openregister_mapping_';

	/**
	 * MappingService constructor
	 *
	 * @param MappingMapperInterface $mappingMapper The mapping mapper for database operations
	 * @param ICacheFactory $cacheFactory Cache factory for distributed caching
	 * @param LoggerInterface $logger Logger for cache diagnostics
	 * @param IEventDispatcher|null $events Collects Twig functions contributed by other apps.
	 *                                      Nullable so the service still constructs where no
	 *                                      dispatcher is available (tests, early boot).
	 */
	public function __construct(
		private readonly MappingMapperInterface $mappingMapper,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly ?IEventDispatcher $events = null,
	) {
		$this->twig         = $this->createTwig();
		$this->mappingCache = $this->createMappingCache(cacheFactory: $cacheFactory);
	}//end __construct()

	/**
	 * Build the Twig environment mappings render in.
	 *
	 * Autoescape is disabled: mappings transform data (not HTML), and the
	 * sandbox policy does not allow the `escape` filter that autoescaping
	 * injects into every output expression. Functions only another app can
	 * provide — `callSource` needs OpenConnector's CallService, the contract
	 * lookups its contract service — are collected BEFORE the sandbox policy
	 * is built, because a contributed function that is not allowlisted fails
	 * as "unknown function" inside a mapping, nowhere near its registration.
	 *
	 * @return Environment The sandboxed environment.
	 */
	private function createTwig(): Environment {
		$twig = new Environment(new ArrayLoader([]), ['autoescape' => false]);
		(new MappingTwigInstaller(mappingService: $this, mappingMapper: $this->mappingMapper))->installOn(twig: $twig);

		$contributed = $this->contributedFunctions();
		foreach ($contributed['functions'] as $function) {
			$twig->addFunction($function);
		}

		$twig->addExtension((new MappingTwigSandbox())->extension(contributedNames: $contributed['names']));

		return $twig;
	}//end createTwig()

	/**
	 * The distributed cache for mapping entity lookups, or null when it cannot be created.
	 *
	 * @param ICacheFactory $cacheFactory Cache factory for distributed caching.
	 *
	 * @return ICache|null The cache, or null to fall back to the database.
	 */
	private function createMappingCache(ICacheFactory $cacheFactory): ?ICache {
		try {
			return $cacheFactory->createDistributed(self::CACHE_PREFIX);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MappingService] Failed to initialize distributed cache, falling back to DB-only',
				context: ['error' => $e->getMessage()]
			);
			return null;
		}
	}//end createMappingCache()

	/**
	 * Replaces strings in array keys, helpful for characters like . in array keys.
	 *
	 * @param array             $array   The array to encode the array keys for.
	 * @param MappingKeyRewrite $rewrite The substring to replace in every key, and its replacement.
	 *
	 * @return array The array with encoded array keys
	 *
	 * @spec exclude Pure array-key encoding helper used internally by executeMapping; no standalone behavior.
	 */
	public function encodeArrayKeys(array $array, MappingKeyRewrite $rewrite): array {
		$result = [];
		foreach ($array as $key => $value) {
			$newKey          = str_replace($rewrite->search, $rewrite->replacement, (string)$key);
			$result[$newKey] = $value;
			if (is_array($value) === true && $value !== []) {
				$result[$newKey] = $this->encodeArrayKeys(array: $value, rewrite: $rewrite);
			}
		}

		return $result;
	}//end encodeArrayKeys()

	/**
	 * Maps (transforms) an array (input) to a different array (output).
	 *
	 * @param Mapping $mapping The mapping object that forms the recipe for the mapping
	 * @param array $input The array that need to be mapped (transformed) otherwise known as input
	 * @param bool $list Whether we want a list instead of a single item
	 *
	 * @return array The result (output) of the mapping process
	 *
	 * @throws Exception When mapping fails
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function executeMapping(Mapping $mapping, array $input, bool $list = false): array {
		if ($list === true) {
			return $this->executeListMapping(mapping: $mapping, input: $input);
		}

		$dotArray = $this->startOutput(mapping: $mapping, input: $input);
		foreach ($this->mappedValues(mapping: $mapping, input: $input) as $key => $value) {
			$dotArray->set($key, $value);
		}

		$this->applyUnsets(mapping: $mapping, dotArray: $dotArray);
		$this->applyCasts(mapping: $mapping, dotArray: $dotArray);

		$output = $this->encodeArrayKeys(array: $dotArray->all(), rewrite: MappingKeyRewrite::decodeDots());

		return $this->unwrapRootValue(output: $output);
	}//end executeMapping()

	/**
	 * The output a mapping starts from: empty, or for pass-through the input with dots in keys encoded.
	 *
	 * @param Mapping $mapping The mapping recipe.
	 * @param array   $input   The input of the mapping.
	 *
	 * @return Dot The output to build on.
	 */
	private function startOutput(Mapping $mapping, array $input): Dot {
		if ($mapping->getPassThrough() === true) {
			return new Dot($this->encodeArrayKeys(array: $input, rewrite: MappingKeyRewrite::encodeDots()));
		}

		return new Dot();
	}//end startOutput()

	/**
	 * Map every item of a list, passing the extra values of a `listInput` wrapper down to each item.
	 *
	 * @param Mapping $mapping The mapping recipe.
	 * @param array   $input   The list, or a `listInput` wrapper with extra values.
	 *
	 * @return array The mapped items, keyed as in the list.
	 *
	 * @throws Exception When mapping an item fails
	 */
	private function executeListMapping(Mapping $mapping, array $input): array {
		$listResult  = [];
		$extraValues = [];

		if (array_key_exists('listInput', $input) === true) {
			$extraValues = $input;
			$input       = $input['listInput'];
			unset($extraValues['listInput'], $extraValues['value']);
		}

		foreach ($input as $key => $value) {
			$itemInput        = $this->listItemInput(value: $value, extraValues: $extraValues);
			$listResult[$key] = $this->executeMapping(mapping: $mapping, input: $itemInput);
		}

		return $listResult;
	}//end executeListMapping()

	/**
	 * The input for one list item: the item itself, or, for a scalar item or
	 * when the list carries extra values, the item merged with `value` and the extras.
	 *
	 * The mapping function expects an array for its input, so this always returns one.
	 *
	 * @param mixed $value       The list item.
	 * @param array $extraValues The extra values of a `listInput` wrapper.
	 *
	 * @return array The item's input.
	 */
	private function listItemInput(mixed $value, array $extraValues): array {
		if (is_array($value) === false || empty($extraValues) === false) {
			return array_merge((array)$value, ['value' => $value], $extraValues);
		}

		return $value;
	}//end listItemInput()

	/**
	 * The value of every mapping rule, in rule order.
	 *
	 * A rule whose value is a path in the input takes the value found there; an
	 * array value is used as it is; any other value is rendered as a Twig
	 * template against the input.
	 *
	 * @param Mapping $mapping The mapping recipe.
	 * @param array   $input   The input of the mapping.
	 *
	 * @return array<int|string, mixed> Output key => value.
	 *
	 * @throws Exception When a template fails to render
	 */
	private function mappedValues(Mapping $mapping, array $input): array {
		$dotInput = new Dot($this->encodeArrayKeys(array: $input, rewrite: MappingKeyRewrite::encodeDots()));
		$values   = [];

		foreach ($mapping->getMapping() as $key => $value) {
			if ($dotInput->has($value) === true) {
				$values[$key] = $dotInput->get($value);
				continue;
			}

			$values[$key] = $value;
			if (is_array($value) === false) {
				$values[$key] = $this->renderRule(mapping: $mapping, key: $key, template: (string)$value, input: $input);
			}
		}

		return $values;
	}//end mappedValues()

	/**
	 * Render one rule's value as a Twig template against the input.
	 *
	 * @param Mapping    $mapping  The mapping recipe, named in the error.
	 * @param int|string $key      The output key, named in the error.
	 * @param string     $template The rule's template.
	 * @param array      $input    The input of the mapping.
	 *
	 * @return string The rendered value, HTML entities decoded.
	 *
	 * @throws Exception When the template fails to render
	 */
	private function renderRule(Mapping $mapping, int|string $key, string $template, array $input): string {
		try {
			return html_entity_decode($this->getCachedTemplate(templateString: $template)->render($input));
		} catch (Throwable $e) {
			$mappingName = $mapping->getName() ?? 'Unknown';
			throw new Exception(
				"Error for mapping: {$mappingName}, key: $key, value: $template and message: {$e->getMessage()}"
			);
		}
	}//end renderRule()

	/**
	 * Remove the mapping's unset keys from the output.
	 *
	 * @param Mapping $mapping  The mapping recipe.
	 * @param Dot     $dotArray The output being built.
	 *
	 * @return void
	 */
	private function applyUnsets(Mapping $mapping, Dot $dotArray): void {
		foreach ($mapping->getUnset() as $unset) {
			if ($dotArray->has($unset) === false) {
				continue;
			}

			$dotArray->delete($unset);
		}
	}//end applyUnsets()

	/**
	 * Apply the mapping's casts to the output keys that exist.
	 *
	 * A cast is a list or a comma-separated string; each cast in it applies in order.
	 *
	 * @param Mapping $mapping  The mapping recipe.
	 * @param Dot     $dotArray The output being built.
	 *
	 * @return void
	 */
	private function applyCasts(Mapping $mapping, Dot $dotArray): void {
		foreach ($mapping->getCast() as $key => $cast) {
			if ($dotArray->has($key) === false) {
				continue;
			}

			if (is_array($cast) === false) {
				$cast = explode(',', (string)$cast);
			}

			foreach ($cast as $singleCast) {
				$this->handleCast(cast: $this->parseCast(cast: $singleCast) + ['key' => $key, 'dotArray' => $dotArray]);
			}
		}
	}//end applyCasts()

	/**
	 * Write a root-level value: an output holding only `#` becomes that value.
	 *
	 * A null root becomes an empty array, a scalar root a one-item list.
	 *
	 * @param array $output The mapped output.
	 *
	 * @return array The output to return.
	 */
	private function unwrapRootValue(array $output): array {
		$keys = array_keys($output);
		if (count($keys) !== 1 || $keys[0] !== '#') {
			return $output;
		}

		$rootValue = $output['#'];
		if ($rootValue === null) {
			return [];
		}

		if (is_array($rootValue) === true) {
			return $rootValue;
		}

		return [$rootValue];
	}//end unwrapRootValue()

	/**
	 * Handles a single cast operation.
	 *
	 * @param array{type: string, argument: string|null, key: string, dotArray: Dot} $cast The parsed cast,
	 *        the key of the field being cast and the dot array of the document being mapped.
	 *
	 * @return void
	 */
	private function handleCast(array $cast): void {
		$dotArray = $cast['dotArray'];
		$value = $this->applyCast(value: $dotArray->get($cast['key']), cast: $cast);

		// Don't reset key that was deleted on purpose.
		if ($dotArray->has($cast['key']) === true) {
			$dotArray->set($cast['key'], $value);
		}
	}//end handleCast()

	/**
	 * Split a cast into its type and its argument.
	 *
	 * `unsetIfValue==<v>`, `setNullIfValue==<v>` and `countValue:<key>` carry an
	 * argument; every other cast is a bare type.
	 *
	 * @param string $cast The cast as written in the mapping.
	 *
	 * @return array{type: string, argument: string|null} The cast type and its argument.
	 */
	private function parseCast(string $cast): array {
		foreach (self::ARGUMENT_CASTS as $prefix => $type) {
			if (str_starts_with($cast, $prefix) === true) {
				return ['type' => $type, 'argument' => substr($cast, strlen($prefix))];
			}
		}

		return ['type' => $cast, 'argument' => null];
	}//end parseCast()

	/**
	 * Apply a specific cast to a value.
	 *
	 * @param mixed                $value The value to cast.
	 * @param array<string, mixed> $cast  The cast type and argument, the key being cast and the dot array.
	 *
	 * @return mixed The cast value; an unknown cast leaves the value unchanged.
	 */
	private function applyCast(mixed $value, array $cast): mixed {
		$type = $cast['type'];
		if (isset(self::VALUE_FUNCTION_CASTS[$type]) === true) {
			return (self::VALUE_FUNCTION_CASTS[$type])($value);
		}

		if (isset(self::STRING_FUNCTION_CASTS[$type]) === true) {
			return (self::STRING_FUNCTION_CASTS[$type])((string)$value);
		}

		return $this->applyNamedCast(value: $value, cast: $cast);
	}//end applyCast()

	/**
	 * Apply a cast that is not a plain PHP function.
	 *
	 * @param mixed                $value The value to cast.
	 * @param array<string, mixed> $cast  The cast type and argument, the key being cast and the dot array.
	 *
	 * @return mixed The cast value; an unknown cast leaves the value unchanged.
	 */
	private function applyNamedCast(mixed $value, array $cast): mixed {
		return match ($cast['type']) {
			'bool', 'boolean' => $this->isTrueCastValue(value: $value),
			'?bool', '?boolean' => $this->castToNullableBool(value: $value),
			'array' => (array)$value,
			'jsonToArray' => $this->castJsonToArray(value: $value),
			'utf8' => $this->castToAscii(value: $value),
			'nullStringToNull' => $this->castNullStringToNull(value: $value),
			'coordinateStringToArray' => $this->coordinateStringToArray(coordinates: (string)$value),
			'moneyStringToInt' => (int)str_replace(',', '', str_replace('.', '', (string)$value)),
			'intToMoneyString' => number_format(($value / 100), 2, ',', '.'),
			default => $this->applyDocumentCast(value: $value, cast: $cast),
		};
	}//end applyNamedCast()

	/**
	 * Whether a value reads as true: 1, `true` or `yes` (case-insensitive).
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a truthy value.
	 */
	private function isTrueCastValue(mixed $value): bool {
		return (int)$value === 1 || strtolower((string)$value) === 'true' || strtolower((string)$value) === 'yes';
	}//end isTrueCastValue()

	/**
	 * Cast to a boolean, keeping null and the empty string as null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool|null The boolean, or null for an empty value.
	 */
	private function castToNullableBool(mixed $value): ?bool {
		if ($value === null || $value === '') {
			return null;
		}

		return $this->isTrueCastValue(value: $value);
	}//end castToNullableBool()

	/**
	 * Decode a JSON string (HTML entities decoded first); an array passes unchanged.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The decoded value.
	 */
	private function castJsonToArray(mixed $value): mixed {
		if (is_array($value) === true) {
			return $value;
		}

		return json_decode(html_entity_decode((string)$value), true);
	}//end castJsonToArray()

	/**
	 * Transliterate a UTF-8 string to ASCII.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|false The ASCII string, or false when iconv fails.
	 */
	private function castToAscii(mixed $value): string|false {
		setlocale(category: LC_CTYPE, locales: 'cs_CZ');
		return iconv('UTF-8', 'ASCII//TRANSLIT', (string)$value);
	}//end castToAscii()

	/**
	 * Turn the string `null` into null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed Null for `null`, otherwise the value.
	 */
	private function castNullStringToNull(mixed $value): mixed {
		if ($value === 'null') {
			return null;
		}

		return $value;
	}//end castNullStringToNull()

	/**
	 * Apply a cast that reads or changes the mapped document.
	 *
	 * `keyCantBeValue` and `unsetIfValue` may delete the key; `setNullIfValue`
	 * may null the value; `countValue` counts another key's value.
	 *
	 * @param mixed                $value The value to cast.
	 * @param array<string, mixed> $cast  The cast type and argument, the key being cast and the dot array.
	 *
	 * @return mixed The cast value; an unknown cast leaves the value unchanged.
	 */
	private function applyDocumentCast(mixed $value, array $cast): mixed {
		return match ($cast['type']) {
			'keyCantBeValue', 'unsetIfValue' => $this->unsetKeyWhenMatching(value: $value, cast: $cast),
			'setNullIfValue' => $this->nullWhenMatching(value: $value, argument: $cast['argument']),
			'countValue' => $this->countCastValue(value: $value, cast: $cast),
			default => $value,
		};
	}//end applyDocumentCast()

	/**
	 * Delete the key being cast when the value matches; the value is returned unchanged.
	 *
	 * `keyCantBeValue` matches a value equal to the key's own name,
	 * `unsetIfValue` a value matching the cast argument.
	 *
	 * @param mixed                $value The value being cast.
	 * @param array<string, mixed> $cast  The cast type and argument, the key being cast and the dot array.
	 *
	 * @return mixed The value.
	 */
	private function unsetKeyWhenMatching(mixed $value, array $cast): mixed {
		$matches = $this->matchesCastArgument(value: $value, argument: $cast['argument']);
		if ($cast['type'] === 'keyCantBeValue') {
			$matches = ($cast['key'] === $value);
		}

		if ($matches === true) {
			$cast['dotArray']->delete($cast['key']);
		}

		return $value;
	}//end unsetKeyWhenMatching()

	/**
	 * The `setNullIfValue` cast: null when the value matches the argument.
	 *
	 * @param mixed       $value    The value being cast.
	 * @param string|null $argument The cast argument.
	 *
	 * @return mixed Null on a match, otherwise the value.
	 */
	private function nullWhenMatching(mixed $value, ?string $argument): mixed {
		if ($this->matchesCastArgument(value: $value, argument: $argument) === true) {
			return null;
		}

		return $value;
	}//end nullWhenMatching()

	/**
	 * The `countValue:<key>` cast: the number of items under another key.
	 *
	 * @param mixed                $value The value to cast.
	 * @param array<string, mixed> $cast  The cast argument (the key to count) and the dot array.
	 *
	 * @return mixed The count, or the value when the key holds nothing countable.
	 */
	private function countCastValue(mixed $value, array $cast): mixed {
		$countKey = $cast['argument'];
		$dotArray = $cast['dotArray'];
		if (empty($countKey) === false && $dotArray->has($countKey) === true && is_countable($dotArray->get($countKey)) === true) {
			return count($dotArray->get($countKey));
		}

		return $value;
	}//end countCastValue()

	/**
	 * Whether a value matches the argument of `unsetIfValue` or `setNullIfValue`.
	 *
	 * The value equals the argument, or the argument is empty and the value is
	 * empty or an array whose leaves are all empty.
	 *
	 * @param mixed       $value    The value.
	 * @param string|null $argument The cast argument.
	 *
	 * @return bool True when the cast applies.
	 */
	private function matchesCastArgument(mixed $value, ?string $argument): bool {
		if ($argument !== null && $value === $argument) {
			return true;
		}

		return $argument === '' && $this->isEmptyCastValue(value: $value) === true;
	}//end matchesCastArgument()

	/**
	 * Whether a value is empty, or an array whose leaves are all empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an empty value.
	 */
	private function isEmptyCastValue(mixed $value): bool {
		return empty($value) === true || (is_array($value) === true && $this->areAllArrayKeysNull(array: $value) === true);
	}//end isEmptyCastValue()

	/**
	 * Checks if all keys in multi-dimensional array are null.
	 *
	 * @param array $array Array to check.
	 *
	 * @return bool True if array keys are null else false.
	 */
	private function areAllArrayKeysNull(array $array): bool {
		foreach ($array as $value) {
			if ($this->isEmptyLeaf(value: $value) === false) {
				return false;
			}
		}

		return true;
	}//end areAllArrayKeysNull()

	/**
	 * Whether a value is empty: an array when all its keys are, anything else by empty().
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an empty value.
	 */
	private function isEmptyLeaf(mixed $value): bool {
		if (is_array($value) === true) {
			return $this->areAllArrayKeysNull(array: $value);
		}

		return empty($value);
	}//end isEmptyLeaf()

	/**
	 * Returns a compiled Twig template from the in-memory cache, compiling on first use.
	 *
	 * Avoids re-parsing the same Twig template string on repeated calls within a request.
	 * Templates are keyed by their SHA-256 hash for fast lookup.
	 *
	 * @param string $templateString The Twig template source string
	 *
	 * @return TemplateWrapper The compiled template ready for rendering
	 */
	private function getCachedTemplate(string $templateString): TemplateWrapper {
		$cacheKey = hash('sha256', $templateString);

		if (isset($this->templateCache[$cacheKey]) === false) {
			$this->templateCache[$cacheKey] = $this->twig->createTemplate($templateString);
		}

		return $this->templateCache[$cacheKey];
	}//end getCachedTemplate()

	/**
	 * Converts a coordinate string to an array of coordinates.
	 *
	 * @param string $coordinates A string containing coordinates.
	 *
	 * @return array An array of coordinates; empty for a blank string.
	 *
	 * @spec exclude Pure coordinate-string parsing helper; no orchestration or persisted state.
	 */
	public function coordinateStringToArray(string $coordinates): array {
		if (trim($coordinates) === '') {
			return [];
		}

		// Pairs of space-separated values; an odd value out ends as a pair of one.
		$coordinateArray = array_chunk(explode(' ', $coordinates), 2);
		if (count($coordinateArray) === 1) {
			return $coordinateArray[0];
		}

		return $coordinateArray;
	}//end coordinateStringToArray()

	/**
	 * Retrieves a single mapping by its ID, with distributed caching.
	 *
	 * Checks the distributed cache (APCu/Redis) first. On cache miss, fetches from
	 * the database and stores the serialized entity for subsequent requests.
	 *
	 * @param string $mappingId The unique identifier of the mapping to retrieve
	 *
	 * @return Mapping The requested mapping entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If mapping is not found
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple mappings found
	 *
	 * @spec exclude Cache-wrapped read delegating to MappingMapper::find; read-through caching plumbing.
	 */
	public function getMapping(string $mappingId): Mapping {
		$mapping = $this->cachedMapping(mappingId: $mappingId);
		if ($mapping !== null) {
			return $mapping;
		}

		// Cache miss — fetch from database, then store for subsequent requests.
		$mapping = $this->mappingMapper->find($mappingId);
		$this->mappingCache?->set($mappingId, $mapping->jsonSerialize(), self::MAPPING_CACHE_TTL);

		return $mapping;
	}//end getMapping()

	/**
	 * The mapping from the distributed cache, or null when it is not there.
	 *
	 * @param string $mappingId The mapping's identifier.
	 *
	 * @return Mapping|null The cached mapping, hydrated with its id.
	 */
	private function cachedMapping(string $mappingId): ?Mapping {
		$cached = $this->mappingCache?->get($mappingId);
		if ($cached === null) {
			return null;
		}

		$mapping = new Mapping();
		$mapping->hydrate($cached);
		if (isset($cached['id']) === true) {
			$mapping->setId($cached['id']);
		}

		return $mapping;
	}//end cachedMapping()

	/**
	 * Retrieves all available mappings.
	 *
	 * @return Mapping[] An array containing all mapping entities
	 */
	public function getMappings(): array {
		return $this->mappingMapper->findAll();
	}//end getMappings()

	/**
	 * Collect Twig functions contributed by other apps.
	 *
	 * Best-effort by design. A contributing app that is absent, or a listener
	 * that throws, must not stop mappings from evaluating at all — the engine
	 * belongs to OpenRegister and has to work on an instance where no other app
	 * is installed. What it must NOT do is pretend a contributed function
	 * exists: an unregistered function fails loudly inside the mapping, which is
	 * the correct place for that failure to surface.
	 *
	 * @return array{functions: array<int, \Twig\TwigFunction>, names: array<int, string>}
	 *                                                                                     The contributed functions and the names to allowlist.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
	 */
	private function contributedFunctions(): array {
		if ($this->events === null) {
			return ['functions' => [], 'names' => []];
		}

		try {
			return $this->dispatchContributedFunctions(events: $this->events);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MappingService] Could not collect contributed Twig functions: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return ['functions' => [], 'names' => []];
		}//end try
	}//end contributedFunctions()

	/**
	 * Ask other apps for their Twig functions.
	 *
	 * @param IEventDispatcher $events The dispatcher the contributing apps listen on.
	 *
	 * @return array{functions: array<int, \Twig\TwigFunction>, names: array<int, string>} The functions and names.
	 */
	private function dispatchContributedFunctions(IEventDispatcher $events): array {
		$event = new RegisterMappingFunctionsEvent();
		$events->dispatchTyped($event);

		return [
			'functions' => $event->getFunctions(),
			'names' => $event->getAllowedNames(),
		];
	}//end dispatchContributedFunctions()
}//end class
