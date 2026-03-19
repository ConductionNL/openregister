<?php

/**
 * OpenRegister Mapping Service
 *
 * Service for executing data mappings using Twig templating and dot notation.
 * Provides data transformation capabilities between different formats.
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
use Throwable;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use OCP\ICacheFactory;
use OCP\ICache;
use Psr\Log\LoggerInterface;
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
class MappingService
{

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
     * @param MappingMapper   $mappingMapper The mapping mapper for database operations
     * @param ICacheFactory   $cacheFactory  Cache factory for distributed caching
     * @param LoggerInterface $logger        Logger for cache diagnostics
     */
    public function __construct(
        private readonly MappingMapper $mappingMapper,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
    ) {
        $loader     = new ArrayLoader([]);
        $this->twig = new Environment($loader);
        $this->twig->addExtension(new MappingExtension());
        $this->twig->addRuntimeLoader(
            new MappingRuntimeLoader(
                mappingService: $this,
                mappingMapper: $this->mappingMapper,
            )
        );

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
     * @param array  $array       The array to encode the array keys for.
     * @param string $toReplace   The character to encode.
     * @param string $replacement The encoded character.
     *
     * @return array The array with encoded array keys
     */
    public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = str_replace($toReplace, $replacement, (string) $key);

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
     * @param array   $input   The array that need to be mapped (transformed) otherwise known as input
     * @param bool    $list    Whether we want a list instead of a single item
     *
     * @return array The result (output) of the mapping process
     *
     * @throws Exception When mapping fails
     *
     */
    public function executeMapping(Mapping $mapping, array $input, bool $list=false): array
    {
        // Check for list.
        if ($list === true) {
            $listResult  = [];
            $extraValues = [];

            // Allow extra(input)values to be passed down for mapping while dealing with a list.
            if (array_key_exists('listInput', $input) === true) {
                $extraValues = $input;
                $input       = $input['listInput'];
                unset($extraValues['listInput'], $extraValues['value']);
            }

            foreach ($input as $key => $value) {
                // Mapping function expects an array for $input, make sure we always pass an array.
                if (is_array($value) === false || empty($extraValues) === false) {
                    $value = array_merge((array) $value, ['value' => $value], $extraValues);
                }

                $listResult[$key] = $this->executeMapping(mapping: $mapping, input: $value);
            }

            return $listResult;
        }//end if

        $originalInput = $input;
        $input         = $this->encodeArrayKeys(array: $input, toReplace: '.', replacement: '&#46;');

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
                $template = $this->getCachedTemplate(templateString: (string) $value);
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
                $cast = explode(',', (string) $cast);
            }

            if ($cast === false) {
                continue;
            }

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
            $output    = ($rootValue === null) ? [] : (is_array($rootValue) ? $rootValue : [$rootValue]);
        }

        // Ensure output is always an array.
        if (is_array($output) === false) {
            $output = ($output === null) ? [] : [$output];
        }

        return $output;
    }//end executeMapping()

    /**
     * Handles a single cast operation.
     *
     * @param Dot    $dotArray The dotArray of the array we are mapping.
     * @param string $key      The key of the field we want to cast.
     * @param string $cast     The type of cast we want to do.
     *
     * @return void
     *
     */
    private function handleCast(Dot $dotArray, string $key, string $cast): void
    {
        $value          = $dotArray->get($key);
        $unsetIfValue   = null;
        $setNullIfValue = null;
        $countValue     = null;

        if (str_starts_with($cast, 'unsetIfValue==') === true) {
            $unsetIfValue = substr($cast, 14);
            $cast         = 'unsetIfValue';
        } else if (str_starts_with($cast, 'setNullIfValue==') === true) {
            $setNullIfValue = substr($cast, 16);
            $cast           = 'setNullIfValue';
        } else if (str_starts_with($cast, 'countValue:') === true) {
            $countValue = substr($cast, 11);
            $cast       = 'countValue';
        }

        $value = $this->applyCast(
            value: $value,
            cast: $cast,
            key: $key,
            dotArray: $dotArray,
            unsetIfValue: $unsetIfValue,
            setNullIfValue: $setNullIfValue,
            countValue: $countValue
        );

        // Don't reset key that was deleted on purpose.
        if ($dotArray->has($key) === true) {
            $dotArray->set($key, $value);
        }
    }//end handleCast()

    /**
     * Apply a specific cast to a value.
     *
     * @param mixed       $value          The value to cast.
     * @param string      $cast           The cast type.
     * @param string      $key            The key being cast.
     * @param Dot         $dotArray       The dot array.
     * @param string|null $unsetIfValue   Value to unset if matched.
     * @param string|null $setNullIfValue Value to set null if matched.
     * @param string|null $countValue     Key to count.
     *
     * @return mixed The cast value.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function applyCast(
        mixed $value,
        string $cast,
        string $key,
        Dot $dotArray,
        ?string $unsetIfValue=null,
        ?string $setNullIfValue=null,
        ?string $countValue=null
    ): mixed {
        switch ($cast) {
            case 'string':
                return (string) $value;

            case 'bool':
            case 'boolean':
                if ((int) $value === 1 || strtolower((string) $value) === 'true' || strtolower((string) $value) === 'yes') {
                    return true;
                }
                return false;

            case '?bool':
            case '?boolean':
                if ($value === null || $value === '') {
                    return null;
                }

                if ((int) $value === 1 || strtolower((string) $value) === 'true' || strtolower((string) $value) === 'yes') {
                    return true;
                }
                return false;

            case 'int':
            case 'integer':
                return (int) $value;

            case 'float':
                return (float) $value;

            case 'array':
                return (array) $value;

            case 'date':
                return date((string) $value);

            case 'url':
                return urlencode((string) $value);

            case 'urlDecode':
                return urldecode((string) $value);

            case 'rawurl':
                return rawurlencode((string) $value);

            case 'rawurlDecode':
                return rawurldecode((string) $value);

            case 'html':
                return htmlentities((string) $value);

            case 'htmlDecode':
                return html_entity_decode((string) $value);

            case 'base64':
                return base64_encode((string) $value);

            case 'base64Decode':
                return base64_decode((string) $value);

            case 'json':
                return json_encode($value);

            case 'jsonToArray':
                if (is_array($value) === true) {
                    return $value;
                }

                $decoded = html_entity_decode((string) $value);
                return json_decode($decoded, true);

            case 'utf8':
                setlocale(category: LC_CTYPE, locales: 'cs_CZ');
                return iconv('UTF-8', 'ASCII//TRANSLIT', (string) $value);

            case 'nullStringToNull':
                if ($value === 'null') {
                    return null;
                }
                return $value;

            case 'coordinateStringToArray':
                return $this->coordinateStringToArray(coordinates: (string) $value);

            case 'keyCantBeValue':
                if ($key === $value) {
                    $dotArray->delete($key);
                }
                return $value;

            case 'unsetIfValue':
                if ($unsetIfValue !== null && $value === $unsetIfValue) {
                    $dotArray->delete($key);
                } else if ($unsetIfValue === '' && (empty($value) === true || $value === null)) {
                    $dotArray->delete($key);
                } else if ($unsetIfValue === ''
                    && is_array($value) === true
                    && $this->areAllArrayKeysNull(array: $value) === true
                ) {
                    $dotArray->delete($key);
                }
                return $value;

            case 'setNullIfValue':
                if ($setNullIfValue !== null && $value === $setNullIfValue) {
                    return null;
                }

                if ($setNullIfValue === '' && (empty($value) === true || $value === null)) {
                    return null;
                }

                if ($setNullIfValue === ''
                    && is_array($value) === true
                    && $this->areAllArrayKeysNull(array: $value) === true
                ) {
                    return null;
                }
                return $value;

            case 'countValue':
                if ($countValue !== null
                    && empty($countValue) === false
                    && $dotArray->has($countValue) === true
                    && is_countable($dotArray->get($countValue)) === true
                ) {
                    return count($dotArray->get($countValue));
                }
                return $value;

            case 'moneyStringToInt':
                $cleaned = str_replace('.', '', (string) $value);
                return (int) str_replace(',', '', $cleaned);

            case 'intToMoneyString':
                $number = ($value / 100);
                return number_format($number, 2, ',', '.');

            default:
                return $value;
        }//end switch
    }//end applyCast()

    /**
     * Checks if all keys in multi-dimensional array are null.
     *
     * @param array $array Array to check.
     *
     * @return bool True if array keys are null else false.
     */
    private function areAllArrayKeysNull(array $array): bool
    {
        if (empty($array) === true) {
            return true;
        }

        foreach ($array as $value) {
            if (is_array($value) === true) {
                if ($this->areAllArrayKeysNull(array: $value) === false) {
                    return false;
                }
            } else if (empty($value) === false) {
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
    private function getCachedTemplate(string $templateString): TemplateWrapper
    {
        $cacheKey = hash('sha256', $templateString);

        if (isset($this->templateCache[$cacheKey]) === false) {
            $this->templateCache[$cacheKey] = $this->twig->createTemplate($templateString);
        }

        return $this->templateCache[$cacheKey];
    }//end getCachedTemplate()

    /**
     * Invalidates the distributed cache entry for a mapping.
     *
     * Called by MappingMapper on create, update, or delete to ensure stale data
     * is not served from the cache.
     *
     * @param int|string $id The mapping ID, UUID, or slug to invalidate
     *
     * @return void
     */
    public function invalidateMappingCache(int|string $id): void
    {
        if ($this->mappingCache === null) {
            return;
        }

        $this->mappingCache->remove((string) $id);
    }//end invalidateMappingCache()

    /**
     * Converts a coordinate string to an array of coordinates.
     *
     * @param string $coordinates A string containing coordinates.
     *
     * @return array An array of coordinates.
     */
    public function coordinateStringToArray(string $coordinates): array
    {
        $halves          = explode(' ', $coordinates);
        $point           = [];
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
     * @throws \OCP\AppFramework\Db\DoesNotExistException          If mapping is not found
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple mappings found
     */
    public function getMapping(string $mappingId): Mapping
    {
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
    public function getMappings(): array
    {
        return $this->mappingMapper->findAll();
    }//end getMappings()
}//end class
