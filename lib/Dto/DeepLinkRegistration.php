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
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-18
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
     * Supported placeholders: {uuid}, {id}, {register}, {schema},
     * {contactId}, {contactEmail}, {contactName}, {entityId},
     * and any top-level key from the object data array.
     *
     * Contact placeholders are resolved from the optional contactContext
     * parameter, applied after object-level placeholder resolution.
     *
     * @param array $objectData     The object data from search results
     * @param array $contactContext Optional contact context with keys:
     *                              contactId, contactEmail, contactName
     *
     * @return string The resolved URL
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-18
     */
    public function resolveUrl(array $objectData, array $contactContext=[]): string
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

        // Apply contact context placeholders (after object placeholders).
        if (empty($contactContext) === false) {
            $replacements['{contactId}']    = urlencode((string) ($contactContext['contactId'] ?? ''));
            $replacements['{contactEmail}'] = urlencode((string) ($contactContext['contactEmail'] ?? ''));
            $replacements['{contactName}']  = urlencode((string) ($contactContext['contactName'] ?? ''));
            $replacements['{entityId}']     = $objectData['uuid'] ?? '';
        }

        return strtr($this->urlTemplate, $replacements);
    }//end resolveUrl()
}//end class
