<?php

/**
 * OpenRegister Mapping Twig Extension
 *
 * Registers Twig functions and filters for the mapping engine.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
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

namespace OCA\OpenRegister\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * MappingExtension registers Twig functions and filters for mapping templates.
 *
 * Provides:
 * - Filters: b64enc, b64dec, json_decode, zgw_enum, zgw_enum_reverse, zgw_extract_uuid
 * - Functions: executeMapping, generateUuid
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 */
class MappingExtension extends AbstractExtension
{
    /**
     * Get the Twig filters provided by this extension.
     *
     * @return TwigFilter[] Array of Twig filters
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('b64enc', [MappingRuntime::class, 'b64enc']),
            new TwigFilter('b64dec', [MappingRuntime::class, 'b64dec']),
            new TwigFilter('json_decode', [MappingRuntime::class, 'jsonDecode']),
            new TwigFilter('zgw_enum', [MappingRuntime::class, 'zgwEnum']),
            new TwigFilter('zgw_enum_reverse', [MappingRuntime::class, 'zgwEnumReverse']),
            new TwigFilter('zgw_extract_uuid', [MappingRuntime::class, 'zgwExtractUuid']),
        ];
    }//end getFilters()

    /**
     * Get the Twig functions provided by this extension.
     *
     * @return TwigFunction[] Array of Twig functions
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(name: 'executeMapping', callable: [MappingRuntime::class, 'executeMapping']),
            new TwigFunction(name: 'generateUuid', callable: [MappingRuntime::class, 'generateUuid']),
        ];
    }//end getFunctions()
}//end class
