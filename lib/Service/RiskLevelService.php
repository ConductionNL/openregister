<?php

/**
 * RiskLevelService
 *
 * Computes and persists file risk levels based on detected entity types and counts.
 * Risk levels are stored using Nextcloud's IFilesMetadata API for native integration.
 *
 * ## Risk Classification
 *
 * | Risk Level | Entity Types                              | Examples                                       |
 * |------------|-------------------------------------------|------------------------------------------------|
 * | None       | No entities detected                      | —                                              |
 * | Low        | LOCATION, ORGANIZATION, DATE, IP_ADDRESS  | City names, company names, timestamps          |
 * | Medium     | PERSON, PHONE, ADDRESS                    | Personal names, phone numbers, street addresses|
 * | High       | EMAIL, IBAN                               | Email addresses, bank account numbers          |
 * | Very High  | SSN                                       | BSN numbers, social security numbers           |
 *
 * The highest-risk entity type found in a file determines its base risk level.
 * If the total entity count exceeds 50, the risk level is escalated by one tier
 * (capped at very_high).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use Psr\Log\LoggerInterface;

/**
 * Service for computing and persisting file risk levels.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class RiskLevelService
{
    /**
     * Metadata key used to store the risk level on files.
     */
    public const METADATA_KEY = 'openregister-risk-level';

    /**
     * Risk level constants.
     */
    public const RISK_NONE      = 'none';
    public const RISK_LOW       = 'low';
    public const RISK_MEDIUM    = 'medium';
    public const RISK_HIGH      = 'high';
    public const RISK_VERY_HIGH = 'very_high';

    /**
     * Mapping from entity types to their base risk tier.
     *
     * The highest-risk entity type found in a file determines its base risk level.
     *
     * @var array<string, string>
     */
    private const ENTITY_RISK_MAP = [
        EntityRecognitionHandler::ENTITY_TYPE_SSN          => self::RISK_VERY_HIGH,
        EntityRecognitionHandler::ENTITY_TYPE_EMAIL        => self::RISK_HIGH,
        EntityRecognitionHandler::ENTITY_TYPE_IBAN         => self::RISK_HIGH,
        EntityRecognitionHandler::ENTITY_TYPE_PERSON       => self::RISK_MEDIUM,
        EntityRecognitionHandler::ENTITY_TYPE_PHONE        => self::RISK_MEDIUM,
        EntityRecognitionHandler::ENTITY_TYPE_ADDRESS      => self::RISK_MEDIUM,
        EntityRecognitionHandler::ENTITY_TYPE_LOCATION     => self::RISK_LOW,
        EntityRecognitionHandler::ENTITY_TYPE_ORGANIZATION => self::RISK_LOW,
        EntityRecognitionHandler::ENTITY_TYPE_DATE         => self::RISK_LOW,
        EntityRecognitionHandler::ENTITY_TYPE_IP_ADDRESS   => self::RISK_LOW,
    ];

    /**
     * Numeric ordering for risk level comparison and escalation.
     *
     * @var array<string, int>
     */
    private const RISK_ORDER = [
        self::RISK_NONE      => 0,
        self::RISK_LOW       => 1,
        self::RISK_MEDIUM    => 2,
        self::RISK_HIGH      => 3,
        self::RISK_VERY_HIGH => 4,
    ];

    /**
     * Entity count threshold that triggers escalation by one risk tier.
     */
    public const ESCALATION_THRESHOLD = 50;

    /**
     * Constructor.
     *
     * @param EntityRelationMapper  $entityRelationMapper Mapper for entity-file relations
     * @param IFilesMetadataManager $metadataManager      Nextcloud files metadata manager
     * @param LoggerInterface       $logger               Logger
     */
    public function __construct(
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly IFilesMetadataManager $metadataManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Compute the risk level for a file based on its detected entities.
     *
     * Algorithm:
     * 1. Query all entity relations for the file (with entity type info).
     * 2. Map each entity type to its risk tier via ENTITY_RISK_MAP.
     * 3. Take the highest risk tier found as the base level.
     * 4. If total entity count exceeds ESCALATION_THRESHOLD, bump up one tier.
     *
     * @param int $fileId Nextcloud file ID from oc_filecache
     *
     * @return string Risk level constant (RISK_NONE through RISK_VERY_HIGH)
     */
    public function computeRiskLevel(int $fileId): string
    {
        $entities = $this->entityRelationMapper->findEntitiesForFile($fileId);

        if (empty($entities) === true) {
            return self::RISK_NONE;
        }

        $highestRisk = self::RISK_NONE;

        foreach ($entities as $entity) {
            $entityType = $entity['entity_type'] ?? '';
            $entityRisk = self::ENTITY_RISK_MAP[$entityType] ?? self::RISK_LOW;

            if (self::RISK_ORDER[$entityRisk] > self::RISK_ORDER[$highestRisk]) {
                $highestRisk = $entityRisk;
            }
        }

        // Escalate if entity count exceeds threshold.
        if (count($entities) > self::ESCALATION_THRESHOLD && $highestRisk !== self::RISK_VERY_HIGH) {
            $currentOrder = self::RISK_ORDER[$highestRisk];
            $riskLevels   = array_flip(self::RISK_ORDER);
            $highestRisk  = $riskLevels[$currentOrder + 1] ?? self::RISK_VERY_HIGH;
        }

        return $highestRisk;
    }//end computeRiskLevel()

    /**
     * Compute and persist the risk level to Nextcloud file metadata.
     *
     * @param int $fileId Nextcloud file ID from oc_filecache
     *
     * @return string The computed risk level
     */
    public function updateRiskLevel(int $fileId): string
    {
        $riskLevel = $this->computeRiskLevel($fileId);

        try {
            $metadata = $this->metadataManager->getMetadata($fileId, true);
            $metadata->setString(self::METADATA_KEY, $riskLevel, true);
            $this->metadataManager->saveMetadata($metadata);
        } catch (\Exception $e) {
            $this->logger->warning(
                '[RiskLevelService] Failed to save risk level metadata',
                [
                    'fileId'    => $fileId,
                    'riskLevel' => $riskLevel,
                    'error'     => $e->getMessage(),
                ]
            );
        }

        return $riskLevel;
    }//end updateRiskLevel()

    /**
     * Get the stored risk level for a file from metadata.
     *
     * Returns 'none' if no risk level has been computed yet.
     *
     * @param int $fileId Nextcloud file ID from oc_filecache
     *
     * @return string Risk level constant
     */
    public function getRiskLevel(int $fileId): string
    {
        try {
            $metadata = $this->metadataManager->getMetadata($fileId);
            if ($metadata->hasKey(self::METADATA_KEY) === true) {
                return $metadata->getString(self::METADATA_KEY);
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                '[RiskLevelService] Could not read risk level metadata',
                [
                    'fileId' => $fileId,
                    'error'  => $e->getMessage(),
                ]
            );
        }

        return self::RISK_NONE;
    }//end getRiskLevel()

    /**
     * Register the metadata key with Nextcloud's files metadata system.
     *
     * This must be called from a repair step (not during app boot).
     *
     * @return void
     */
    public function initMetadataKey(): void
    {
        $this->metadataManager->initMetadata(
            self::METADATA_KEY,
            IMetadataValueWrapper::TYPE_STRING,
            true,
            IMetadataValueWrapper::EDIT_FORBIDDEN
        );
    }//end initMetadataKey()

    /**
     * Get all valid risk levels with their labels.
     *
     * Useful for API documentation and frontend dropdowns.
     *
     * @return array<string, string> Map of risk level value to human-readable label
     */
    public static function getAllRiskLevels(): array
    {
        return [
            self::RISK_NONE      => 'None',
            self::RISK_LOW       => 'Low',
            self::RISK_MEDIUM    => 'Medium',
            self::RISK_HIGH      => 'High',
            self::RISK_VERY_HIGH => 'Very High',
        ];
    }//end getAllRiskLevels()
}//end class
