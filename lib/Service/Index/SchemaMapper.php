<?php

/**
 * SchemaMapper
 *
 * Handles mapping between OpenRegister schemas and search backend schemas.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use Psr\Log\LoggerInterface;

/**
 * SchemaMapper for schema translation operations
 *
 * @package OCA\OpenRegister\Service\Index
 */
class SchemaMapper
{

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * SchemaMapper constructor
     *
     * @param LoggerInterface $logger Logger
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }//end __construct()

    /**
     * Map OpenRegister schema to search backend schema
     *
     * @param array $schema OpenRegister schema
     *
     * @return array Search backend schema
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     *
     * @psalm-return array<never, never>
     */
    public function mapToBackendSchema(array $schema): array
    {
        $this->logger->debug('[SchemaMapper] Mapping schema');

        return [];
    }//end mapToBackendSchema()

    /**
     * Map field types from OpenRegister to search backend
     *
     * @param string $fieldType OpenRegister field type
     *
     * @return string Search backend field type
     */
    public function mapFieldType(string $fieldType): string
    {
        return $fieldType;
    }//end mapFieldType()
}//end class
