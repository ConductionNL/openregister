<?php

/**
 * Turns schema markers into shareable configuration types.
 *
 * A schema marks itself shareable by setting `shareable` in its `configuration`
 * (either `true`, or an object refining the `id`, `topic` and `name`). This
 * scanner finds those schemas, works out which registers hold them, and produces
 * one {@see GenericObjectShareableConfigType} per register+schema pair — so an
 * app makes its configuration shareable with a data flag on its schema, never a
 * line of per-app PHP.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Config\Types\GenericObjectShareableConfigType;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovers shareable-marked schemas and builds a type for each.
 */
class SchemaShareableConfigScanner
{
    /**
     * Constructor.
     *
     * @param SchemaMapper    $schemaMapper   Enumerates schemas and their markers.
     * @param RegisterMapper  $registerMapper Finds the registers a schema belongs to.
     * @param ObjectService   $objectService  Handed to each generated type.
     * @param LoggerInterface $logger         The logger.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * The shareable types every marked schema declares.
     *
     * @return array<int, GenericObjectShareableConfigType> The generated types.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function scan(): array
    {
        try {
            $schemas   = $this->schemaMapper->findAll();
            $registers = $this->registerMapper->findAll();
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[SchemaShareableConfigScanner] Could not scan for shareable schemas: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $types = [];
        foreach ($schemas as $schema) {
            $marker = $this->markerOf(configuration: (array) ($schema->getConfiguration() ?? []));
            if ($marker === null) {
                continue;
            }

            $schemaSlug  = (string) $schema->getSlug();
            $schemaTitle = (string) $schema->getTitle();

            // A schema may sit in several registers; each register+schema pair is
            // its own shareable set.
            foreach ($registers as $register) {
                if ($this->registerHasSchema(register: $register, schemaId: (int) $schema->getId()) === false) {
                    continue;
                }

                $registerSlug  = (string) $register->getSlug();
                $registerTitle = (string) $register->getTitle();

                $id    = (string) ($marker['id'] ?? ($registerSlug.'.'.$schemaSlug));
                $topic = (string) ($marker['topic'] ?? ($registerSlug.'-'.$schemaSlug));
                $name  = (string) ($marker['name'] ?? sprintf('%s (%s)', $schemaTitle, $registerTitle));

                $types[] = new GenericObjectShareableConfigType(
                    objectService: $this->objectService,
                    id: $id,
                    name: $name,
                    topic: $topic,
                    register: $registerSlug,
                    schema: $schemaSlug
                );
            }//end foreach
        }//end foreach

        return $types;

    }//end scan()

    /**
     * The shareable annotation key on a schema's configuration.
     *
     * Follows the `x-openregister-*` vendor-extension convention (sibling of
     * `x-openregister-lifecycle`, `-mcp`, …). The key must also be declared in
     * {@see Schema::ANNOTATION_VOCABULARY}, otherwise setConfiguration() drops
     * it before it ever reaches this scanner.
     *
     * @var string
     */
    private const MARKER_KEY = 'x-openregister-shareable';

    /**
     * The shareable marker on a schema's configuration, normalised, or null.
     *
     * Accepts `x-openregister-shareable: true` (all defaults derived) or an
     * object refining `id` / `topic` / `name`. Anything falsy is "not
     * shareable".
     *
     * @param array $configuration The schema configuration.
     *
     * @return array<string, mixed>|null The marker, or null when not shareable.
     */
    private function markerOf(array $configuration): ?array
    {
        $marker = ($configuration[self::MARKER_KEY] ?? null);
        if ($marker === null || $marker === false) {
            return null;
        }

        if (is_array($marker) === true) {
            return $marker;
        }

        // A truthy scalar (e.g. `true`) means "shareable with derived defaults".
        return [];

    }//end markerOf()

    /**
     * Whether a register includes a schema.
     *
     * @param object $register The register.
     * @param int    $schemaId The schema id.
     *
     * @return boolean Whether the register holds the schema.
     */
    private function registerHasSchema(object $register, int $schemaId): bool
    {
        foreach ((array) ($register->getSchemas() ?? []) as $entry) {
            if ((int) $entry === $schemaId) {
                return true;
            }
        }

        return false;

    }//end registerHasSchema()
}//end class
