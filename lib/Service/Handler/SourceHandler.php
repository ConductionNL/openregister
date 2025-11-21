<?php
/**
 * OpenRegister Source Handler
 *
 * This file contains the handler class for Source entity import/export operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handler
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Handler;

use Exception;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use Psr\Log\LoggerInterface;

/**
 * Class SourceHandler
 *
 * Handles import and export operations for Source entities.
 *
 * @package OCA\OpenRegister\Service\Handler
 */
class SourceHandler
{

    /**
     * Source mapper instance.
     *
     * @var SourceMapper The source mapper instance.
     */
    private SourceMapper $sourceMapper;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param SourceMapper    $sourceMapper The source mapper instance
     * @param LoggerInterface $logger       The logger instance
     */
    public function __construct(SourceMapper $sourceMapper, LoggerInterface $logger)
    {
        $this->sourceMapper = $sourceMapper;
        $this->logger       = $logger;

    }//end __construct()


    /**
     * Export a source to array format
     *
     * @param Source $source The source to export
     *
     * @return array The exported source data
     *
     * @psalm-return array<string, mixed>
     */
    public function export(Source $source): array
    {
        $sourceArray = $source->jsonSerialize();
        unset($sourceArray['id'], $sourceArray['uuid']);
        return $sourceArray;

    }//end export()


    /**
     * Import a source from configuration data
     *
     * @param array       $data  The source data
     * @param string|null $owner The owner of the source
     *
     * @return Source The imported source or null if skipped
     *
     * @throws Exception If import fails
     */
    public function import(array $data, ?string $owner=null): Source
    {
        try {
            unset($data['id'], $data['uuid']);

            // Check if source already exists by name.
            $existingSources = $this->sourceMapper->findAll();
            $existingSource  = null;
            foreach ($existingSources as $source) {
                if ($source->getName() === $data['name']) {
                    $existingSource = $source;
                    break;
                }
            }

            if ($existingSource !== null) {
                // Update existing source.
                $existingSource->hydrate($data);
                if ($owner !== null) {
                    $existingSource->setOwner($owner);
                }

                return $this->sourceMapper->update($existingSource);
            }

            // Create new source.
            $source = new Source();
            $source->hydrate($data);
            if ($owner !== null) {
                $source->setOwner($owner);
            }

            return $this->sourceMapper->insert($source);
        } catch (Exception $e) {
            $this->logger->error('Failed to import source: '.$e->getMessage());
            throw $e;
        }//end try

    }//end import()


}//end class
