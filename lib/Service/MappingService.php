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
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
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
class MappingService {

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
	 * @param MappingMapper $mappingMapper The mapping mapper for database operations
	 * @param ICacheFactory $cacheFactory Cache factory for distributed caching
	 * @param LoggerInterface $logger Logger for cache diagnostics
	 * @param IEventDispatcher|null $events Collects Twig functions contributed by other apps.
	 *                                      Nullable so the service still constructs where no
	 *                                      dispatcher is available (tests, early boot).
	 */
	public function __construct(
		private readonly MappingMapper $mappingMapper,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly ?IEventDispatcher $events = null,
	) {
		$loader = new ArrayLoader([]);
		// Autoescape disabled — mappings transform data (not HTML), and the
		// sandbox SecurityPolicy below does not allow the `escape` filter that
		// autoescaping injects into every output expression.
		$this->twig = new Environment($loader, ['autoescape' => false]);
		$this->twig->addExtension(new MappingExtension());

		// Functions only another app can provide — `callSource` needs
		// OpenConnector's CallService, the contract lookups its contract
		// service. Collected BEFORE the sandbox policy is built, because a
		// contributed function that is not allowlisted fails as "unknown
		// function" inside a mapping, nowhere near its registration.
		$contributed = $this->contributedFunctions();
		foreach ($contributed['functions'] as $function) {
			$this->twig->addFunction($function);
		}

		$this->twig->addRuntimeLoader(
			new MappingRuntimeLoader(
				mappingService: $this,
				mappingMapper: $this->mappingMapper,
			)
		);

		// SSTI hardening (SEC-SVC-3): user-authored mapping templates are
		// compiled and rendered here, so they MUST run inside a Twig sandbox.
		// Only the tags, filters and functions actually used by mappings are
		// allowlisted; method/property access on objects is denied entirely.
		$policy = new SecurityPolicy(
			allowedTags: [
				'if',
				'for',
				'set',
				'apply',
			],
			allowedFilters: [
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
				'merge',
				'keys',
				'escape',
				'raw',
				'b64enc',
				'b64dec',
				'json_decode',
				// Twig core's own filter, and the counterpart to the
				// `json_decode` above it. It was missing, so a stored mapping
				// that encodes a value to JSON failed at RENDER with a Twig
				// SecurityError naming a filter, nowhere near the mapping the
				// operator was looking at. dossiq stores several ZGW
				// properties as JSON text and encodes them in twelve of its
				// default mappings; two of those sit in the setUp of the VNG
				// ZGW contract collections, so the zaaktype create 400ed and
				// took every later request in the collection with it.
				// See #3663.
				//
				// It is a pure value transform. The policy already denies
				// every method and property, so this widens nothing an
				// attacker can reach.
				'json_encode',
				'zgw_enum',
				'zgw_enum_reverse',
				'zgw_extract_uuid',
			],
			allowedMethods: [],
			allowedProperties: [],
			allowedFunctions: array_merge(
				[
					'max',
					'min',
					'range',
					'executeMapping',
					'generateUuid',
					// Ported from OpenConnector's copy so its mappings keep
					// working once that copy is retired. `json_decode` is the
					// name OpenConnector's templates use; OpenRegister's own
					// runtime spells it `jsonDecode`. Both are exposed rather
					// than renaming either, because a mapping is authored data
					// — renaming a function silently breaks stored templates.
					'json_decode',
					'jsonDecode',
					'createSlug',
					'getFileContents',
					'getFiles',
					'b64enc',
					'b64dec',
					'zgwEnum',
					'zgwEnumReverse',
					'zgwExtractUuid',
				],
				$contributed['names']
			)
		);
		$this->twig->addExtension(new SandboxExtension($policy, sandboxed: true));

		// Initialize distributed cache for mapping entity lookups.
		try {
			$this->mappingCache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MappingService] Failed to initialize distributed cache, falling back to DB-only',
				context: ['error' => $e->getMessage()]
			);
		}
	}//end __construct()

	/**
	 * Replaces strings in array keys, helpful for characters like . in array keys.
	 *
	 * @param array $array The array to encode the array keys for.
	 * @param string $toReplace The character to encode.
	 * @param string $replacement The encoded character.
	 *
	 * @return array The array with encoded array keys
	 *
	 * @spec exclude Pure array-key encoding helper used internally by executeMapping; no standalone behavior.
	 */
	public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array {
		$result = [];
		foreach ($array as $key => $value) {
			$newKey = str_replace($toReplace, $replacement, (string)$key);

			if (is_array($value) === true && $value !== []) {
				$result[$newKey] = $this->encodeArrayKeys(
					array: $value,
					toReplace: $toReplace,
					replacement: $replacement
				);
				continue;
			}

			$result[$newKey] = $value;
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
		// Check for list.
		if ($list === true) {
			$listResult = [];
			$extraValues = [];

			// Allow extra(input)values to be passed down for mapping while dealing with a list.
			if (array_key_exists('listInput', $input) === true) {
				$extraValues = $input;
				$input = $input['listInput'];
				unset($extraValues['listInput'], $extraValues['value']);
			}

			foreach ($input as $key => $value) {
				// Mapping function expects an array for $input, make sure we always pass an array.
				if (is_array($value) === false || empty($extraValues) === false) {
					$value = array_merge((array)$value, ['value' => $value], $extraValues);
				}

				$listResult[$key] = $this->executeMapping(mapping: $mapping, input: $value);
			}

			return $listResult;
		}//end if

		$originalInput = $input;
		$input = $this->encodeArrayKeys(array: $input, toReplace: '.', replacement: '&#46;');

		// Determine pass through.
		// Let's get the dot array based on https://github.com/adbario/php-dot-notation.
		$dotArray = new Dot();
		if ($mapping->getPassThrough() === true) {
			$dotArray = new Dot($input);
		}

		$dotInput = new Dot($input);

		// Let's do the actual mapping.
		foreach ($mapping->getMapping() as $key => $value) {
			// If the value exists in the input dot take it from there.
			if ($dotInput->has($value) === true) {
				$dotArray->set($key, $dotInput->get($value));
				continue;
			}

			// Render the value from twig.
			if (is_array($value) === true) {
				$dotArray->set($key, $value);
				continue;
			}

			try {
				$template = $this->getCachedTemplate(templateString: (string)$value);
				$rendered = $template->render($originalInput);
				$dotArray->set($key, html_entity_decode($rendered));
			} catch (Throwable $e) {
				$mappingName = $mapping->getName() ?? 'Unknown';
				throw new Exception(
					"Error for mapping: {$mappingName}, key: $key, value: $value and message: {$e->getMessage()}"
				);
			}
		}//end foreach

		// Unset unwanted keys.
		$unsets = $mapping->getUnset();
		foreach ($unsets as $unset) {
			if ($dotArray->has($unset) === false) {
				continue;
			}

			$dotArray->delete($unset);
		}

		// Cast values to a specific type.
		$casts = $mapping->getCast();

		foreach ($casts as $key => $cast) {
			if ($dotArray->has($key) === false) {
				continue;
			}

			if (is_array($cast) === false) {
				$cast = explode(',', (string)$cast);
			}

			// No `$cast === false` bail-out: after the branch above $cast is
			// always an array (explode() cannot return false), so it never fired.

			foreach ($cast as $singleCast) {
				$this->handleCast(dotArray: $dotArray, key: $key, cast: $singleCast);
			}
		}

		// Back to array.
		$output = $dotArray->all();

		$output = $this->encodeArrayKeys(array: $output, toReplace: '&#46;', replacement: '.');

		// Handle root level object writing.
		$keys = array_keys($output);
		if (count($keys) === 1 && $keys[0] === '#') {
			$rootValue = $output['#'];
			if ($rootValue === null) {
				$output = [];
			} elseif (is_array($rootValue) === true) {
				$output = $rootValue;
			}

			if ($rootValue !== null && is_array($rootValue) === false) {
				$output = [$rootValue];
			}
		}

		// Ensure output is always an array — default null to [], wrap scalars.
		if ($output === null) {
			$output = [];
		}

		// No scalar-wrapping branch: after the null default above, $output is
		// always an array, so `is_array() === false` could never be entered.
		return $output;
	}//end executeMapping()

	/**
	 * Handles a single cast operation.
	 *
	 * @param Dot $dotArray The dotArray of the array we are mapping.
	 * @param string $key The key of the field we want to cast.
	 * @param string $cast The type of cast we want to do.
	 *
	 * @return void
	 */
	private function handleCast(Dot $dotArray, string $key, string $cast): void {
		$value = $this->applyCast(
			value: $dotArray->get($key),
			cast: $this->parseCast(cast: $cast) + ['key' => $key, 'dotArray' => $dotArray]
		);

		// Don't reset key that was deleted on purpose.
		if ($dotArray->has($key) === true) {
			$dotArray->set($key, $value);
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

		return match ($type) {
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
	}//end applyCast()

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
		$argument = $cast['argument'];
		$dotArray = $cast['dotArray'];
		switch ($cast['type']) {
			case 'keyCantBeValue':
				if ($cast['key'] === $value) {
					$dotArray->delete($cast['key']);
				}
				return $value;
			case 'unsetIfValue':
				if ($this->matchesCastArgument(value: $value, argument: $argument) === true) {
					$dotArray->delete($cast['key']);
				}
				return $value;
			case 'setNullIfValue':
				if ($this->matchesCastArgument(value: $value, argument: $argument) === true) {
					return null;
				}
				return $value;
			case 'countValue':
				return $this->countCastValue(value: $value, cast: $cast);
			default:
				return $value;
		}//end switch
	}//end applyDocumentCast()

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

		if ($argument !== '') {
			return false;
		}

		return empty($value) === true || (is_array($value) === true && $this->areAllArrayKeysNull(array: $value) === true);
	}//end matchesCastArgument()

	/**
	 * Checks if all keys in multi-dimensional array are null.
	 *
	 * @param array $array Array to check.
	 *
	 * @return bool True if array keys are null else false.
	 */
	private function areAllArrayKeysNull(array $array): bool {
		if (empty($array) === true) {
			return true;
		}

		foreach ($array as $value) {
			if (is_array($value) === true) {
				if ($this->areAllArrayKeysNull(array: $value) === false) {
					return false;
				}
			} elseif (empty($value) === false) {
				return false;
			}
		}

		return true;
	}//end areAllArrayKeysNull()

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
	 * Evicts ONE distributed-cache key for a mapping.
	 *
	 * NOT the write-path invalidation, despite what this docblock used to claim.
	 * It said "Called by MappingMapper on create, update, or delete" and no such
	 * call exists or ever did — `MappingMapper` invalidates through its OWN
	 * private `invalidateCache()`, on all three write paths, against the same
	 * `openregister_mapping_` prefix. The design the sentence described is real;
	 * it just never ran through here.
	 *
	 * Prefer the mapper's version, and understand the difference before reaching
	 * for this one: `getMapping()` caches under WHATEVER identifier the caller
	 * passed, and `MappingMapper::find()` accepts an id, a uuid OR a slug — so
	 * one mapping can sit in the cache under three keys. This method removes
	 * exactly the one it is given. Evicting by id therefore leaves the uuid- and
	 * slug-keyed copies live, which looks like a flush and is not one. The
	 * mapper's `invalidateCache()` removes all three, from the entity.
	 *
	 * Kept as the single-key primitive for a caller that genuinely holds one key
	 * and means one key.
	 *
	 * @param int|string $id The single cache key to remove (id, uuid or slug).
	 *
	 * @return void
	 *
	 * @spec exclude Single-key distributed-cache eviction primitive; the write-path invalidation is
	 *              MappingMapper::invalidateCache(), pinned by MappingMapperCacheInvalidationTest.
	 */
	public function invalidateMappingCache(int|string $id): void {
		if ($this->mappingCache === null) {
			return;
		}

		$this->mappingCache->remove((string)$id);
	}//end invalidateMappingCache()

	/**
	 * Converts a coordinate string to an array of coordinates.
	 *
	 * @param string $coordinates A string containing coordinates.
	 *
	 * @return array An array of coordinates.
	 *
	 * @spec exclude Pure coordinate-string parsing helper; no orchestration or persisted state.
	 */
	public function coordinateStringToArray(string $coordinates): array {
		$halves = explode(' ', $coordinates);
		$point = [];
		$coordinateArray = [];

		foreach ($halves as $half) {
			if (count($point) > 1) {
				$coordinateArray[] = $point;
				$point = [];
			}

			$point[] = $half;
		}//end foreach

		$coordinateArray[] = $point;

		if (count($coordinateArray) === 1) {
			$coordinateArray = $coordinateArray[0];
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
		// Try distributed cache first.
		if ($this->mappingCache !== null) {
			$cached = $this->mappingCache->get($mappingId);
			if ($cached !== null) {
				$mapping = new Mapping();
				$mapping->hydrate($cached);
				if (isset($cached['id']) === true) {
					$mapping->setId($cached['id']);
				}

				return $mapping;
			}
		}

		// Cache miss — fetch from database.
		$mapping = $this->mappingMapper->find($mappingId);

		// Store in distributed cache for subsequent requests.
		if ($this->mappingCache !== null) {
			$this->mappingCache->set($mappingId, $mapping->jsonSerialize(), self::MAPPING_CACHE_TTL);
		}

		return $mapping;
	}//end getMapping()

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
			$event = new RegisterMappingFunctionsEvent();
			$this->events->dispatchTyped($event);

			return [
				'functions' => $event->getFunctions(),
				'names' => $event->getAllowedNames(),
			];
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MappingService] Could not collect contributed Twig functions: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return ['functions' => [], 'names' => []];
		}//end try

	}//end contributedFunctions()
}//end class
