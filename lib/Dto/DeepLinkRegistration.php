<?php

/**
 * OpenRegister DeepLinkRegistration DTO
 *
 * Value object representing a deep link registration from a consuming app.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * Value object representing a deep link registration from a consuming app.
 *
 * Maps a (register, schema) pair to a consuming app's URL template,
 * so that search results link to the correct app instead of OpenRegister.
 */
class DeepLinkRegistration
{
    /**
     * Constructor for DeepLinkRegistration.
     *
     * @param string $appId        The consuming app ID (e.g., "procest")
     * @param string $registerSlug The register slug
     * @param string $schemaSlug   The schema slug
     * @param string $urlTemplate  URL template with placeholders
     * @param string $icon         Optional icon identifier
     *
     * @return void
     */
    public function __construct(
        public readonly string $appId,
        public readonly string $registerSlug,
        public readonly string $schemaSlug,
        public readonly string $urlTemplate,
        public readonly string $icon='',
    ) {
    }//end __construct()

    /**
     * Resolve the URL template by replacing placeholders with object data.
     *
     * Supported placeholders: {uuid}, {id}, {register}, {schema}
     * and any top-level key from the object data array.
     *
     * @param array $objectData The object data from search results
     *
     * @return string The resolved URL
     */
    public function resolveUrl(array $objectData): string
    {
        $replacements = [
            '{uuid}'     => $objectData['uuid'] ?? '',
            '{id}'       => (string) ($objectData['id'] ?? ''),
            '{register}' => (string) ($objectData['register'] ?? ''),
            '{schema}'   => (string) ($objectData['schema'] ?? ''),
        ];

        // Also support any top-level key from object data.
        foreach ($objectData as $key => $value) {
            if (is_scalar($value) === true) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr(string: $this->urlTemplate, replacePairs: $replacements);
    }//end resolveUrl()
}//end class
