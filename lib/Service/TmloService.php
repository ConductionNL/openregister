<?php

/**
 * OpenRegister TMLO Service
 *
 * Service for handling TMLO (Toepassingsprofiel Metadatastandaard Lokale Overheden)
 * archival metadata on OpenRegister objects.
 *
 * Provides:
 * - Auto-population of TMLO defaults from schema/register configuration
 * - Archival status transition validation
 * - TMLO field value validation
 * - MDTO-compliant XML export generation
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateInterval;
use DateTime;
use DOMDocument;
use DOMElement;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;

/**
 * Service for TMLO archival metadata management
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class TmloService
{

    /**
     * Valid values for archiefnominatie field
     */
    public const ARCHIEFNOMINATIE_BLIJVEND_BEWAREN = 'blijvend_bewaren';
    public const ARCHIEFNOMINATIE_VERNIETIGEN      = 'vernietigen';

    /**
     * Valid values for archiefstatus field
     */
    public const ARCHIEFSTATUS_ACTIEF         = 'actief';
    public const ARCHIEFSTATUS_SEMI_STATISCH  = 'semi_statisch';
    public const ARCHIEFSTATUS_OVERGEBRACHT   = 'overgebracht';
    public const ARCHIEFSTATUS_VERNIETIGD     = 'vernietigd';

    /**
     * MDTO XML namespace
     */
    public const MDTO_NAMESPACE = 'https://www.nationaalarchief.nl/mdto';

    /**
     * All valid archiefnominatie values
     *
     * @var string[]
     */
    public const VALID_ARCHIEFNOMINATIE = [
        self::ARCHIEFNOMINATIE_BLIJVEND_BEWAREN,
        self::ARCHIEFNOMINATIE_VERNIETIGEN,
    ];

    /**
     * All valid archiefstatus values
     *
     * @var string[]
     */
    public const VALID_ARCHIEFSTATUS = [
        self::ARCHIEFSTATUS_ACTIEF,
        self::ARCHIEFSTATUS_SEMI_STATISCH,
        self::ARCHIEFSTATUS_OVERGEBRACHT,
        self::ARCHIEFSTATUS_VERNIETIGD,
    ];

    /**
     * All TMLO field names
     *
     * @var string[]
     */
    public const TMLO_FIELDS = [
        'classificatie',
        'archiefnominatie',
        'archiefactiedatum',
        'archiefstatus',
        'bewaarTermijn',
        'vernietigingsCategorie',
    ];

    /**
     * Valid status transitions: from => [allowed targets]
     *
     * @var array<string, string[]>
     */
    public const VALID_TRANSITIONS = [
        self::ARCHIEFSTATUS_ACTIEF        => [self::ARCHIEFSTATUS_SEMI_STATISCH],
        self::ARCHIEFSTATUS_SEMI_STATISCH => [self::ARCHIEFSTATUS_OVERGEBRACHT, self::ARCHIEFSTATUS_VERNIETIGD],
        self::ARCHIEFSTATUS_OVERGEBRACHT  => [],
        self::ARCHIEFSTATUS_VERNIETIGD    => [],
    ];


    /**
     * Constructor.
     *
     * @param RegisterMapper  $registerMapper Register mapper for fetching registers
     * @param SchemaMapper    $schemaMapper   Schema mapper for fetching schemas
     * @param LoggerInterface $logger         Logger interface
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Check if TMLO is enabled for a given register.
     *
     * @param Register $register The register to check
     *
     * @return bool True if TMLO is enabled
     */
    public function isTmloEnabled(Register $register): bool
    {
        $config = $register->getConfiguration();
        return ($config['tmloEnabled'] ?? false) === true;
    }//end isTmloEnabled()


    /**
     * Get TMLO defaults from a schema's configuration.
     *
     * @param Schema $schema The schema to get defaults from
     *
     * @return array The TMLO default values
     */
    public function getSchemaDefaults(Schema $schema): array
    {
        $config = $schema->getConfiguration();
        if (is_array($config) === false) {
            return [];
        }

        return ($config['tmloDefaults'] ?? []);
    }//end getSchemaDefaults()


    /**
     * Populate TMLO defaults on an object entity.
     *
     * Merges schema-level TMLO defaults with any explicitly provided TMLO data.
     * Sets archiefstatus to 'actief' if not already set.
     * Calculates archiefactiedatum from bewaarTermijn if not explicitly provided.
     *
     * @param ObjectEntity $object   The object to populate
     * @param Register     $register The register (must have tmloEnabled=true)
     * @param Schema       $schema   The schema for default values
     *
     * @return ObjectEntity The object with populated TMLO metadata
     */
    public function populateDefaults(ObjectEntity $object, Register $register, Schema $schema): ObjectEntity
    {
        if ($this->isTmloEnabled($register) === false) {
            return $object;
        }

        // Get existing TMLO data from the object (may have been set explicitly).
        $tmlo = $object->getTmlo();
        if (is_array($tmlo) === false || empty($tmlo) === true) {
            $tmlo = [];
        }

        // Get schema-level defaults.
        $defaults = $this->getSchemaDefaults($schema);

        // Merge defaults: only fill in fields that are not already set.
        foreach (self::TMLO_FIELDS as $field) {
            if (isset($tmlo[$field]) === false || $tmlo[$field] === null) {
                $tmlo[$field] = ($defaults[$field] ?? null);
            }
        }

        // Always default archiefstatus to 'actief' if not set.
        if (($tmlo['archiefstatus'] ?? null) === null) {
            $tmlo['archiefstatus'] = self::ARCHIEFSTATUS_ACTIEF;
        }

        // Calculate archiefactiedatum from bewaarTermijn if not explicitly set.
        if (($tmlo['archiefactiedatum'] ?? null) === null && ($tmlo['bewaarTermijn'] ?? null) !== null) {
            $tmlo['archiefactiedatum'] = $this->calculateArchiefactiedatum($tmlo['bewaarTermijn']);
        }

        $object->setTmlo($tmlo);

        return $object;
    }//end populateDefaults()


    /**
     * Calculate archiefactiedatum from an ISO-8601 duration string.
     *
     * @param string $duration ISO-8601 duration (e.g., P7Y, P5Y6M)
     *
     * @return string|null ISO-8601 date string or null if invalid duration
     */
    public function calculateArchiefactiedatum(string $duration): ?string
    {
        try {
            $interval = new DateInterval($duration);
            $date     = new DateTime();
            $date->add($interval);
            return $date->format('Y-m-d');
        } catch (Exception $e) {
            $this->logger->warning(
                'Failed to calculate archiefactiedatum from duration: '.$duration,
                ['exception' => $e]
            );
            return null;
        }
    }//end calculateArchiefactiedatum()


    /**
     * Validate TMLO field values.
     *
     * Checks that all provided TMLO field values conform to allowed values.
     *
     * @param array $tmlo The TMLO metadata to validate
     *
     * @return array Array of validation errors (empty if valid)
     */
    public function validateFieldValues(array $tmlo): array
    {
        $errors = [];

        // Validate archiefnominatie.
        if (isset($tmlo['archiefnominatie']) === true
            && $tmlo['archiefnominatie'] !== null
            && in_array($tmlo['archiefnominatie'], self::VALID_ARCHIEFNOMINATIE, true) === false
        ) {
            $errors[] = 'archiefnominatie must be one of: '.implode(', ', self::VALID_ARCHIEFNOMINATIE)
                .'. Got: '.$tmlo['archiefnominatie'];
        }

        // Validate archiefstatus.
        if (isset($tmlo['archiefstatus']) === true
            && $tmlo['archiefstatus'] !== null
            && in_array($tmlo['archiefstatus'], self::VALID_ARCHIEFSTATUS, true) === false
        ) {
            $errors[] = 'archiefstatus must be one of: '.implode(', ', self::VALID_ARCHIEFSTATUS)
                .'. Got: '.$tmlo['archiefstatus'];
        }

        // Validate bewaarTermijn as ISO-8601 duration.
        if (isset($tmlo['bewaarTermijn']) === true && $tmlo['bewaarTermijn'] !== null) {
            try {
                new DateInterval($tmlo['bewaarTermijn']);
            } catch (Exception $e) {
                $errors[] = 'bewaarTermijn must be a valid ISO-8601 duration (e.g., P7Y, P5Y6M). Got: '
                    .$tmlo['bewaarTermijn'];
            }
        }

        // Validate archiefactiedatum as ISO-8601 date.
        if (isset($tmlo['archiefactiedatum']) === true && $tmlo['archiefactiedatum'] !== null) {
            $date = DateTime::createFromFormat('Y-m-d', $tmlo['archiefactiedatum']);
            if ($date === false || $date->format('Y-m-d') !== $tmlo['archiefactiedatum']) {
                $errors[] = 'archiefactiedatum must be a valid ISO-8601 date (YYYY-MM-DD). Got: '
                    .$tmlo['archiefactiedatum'];
            }
        }

        return $errors;
    }//end validateFieldValues()


    /**
     * Validate an archival status transition.
     *
     * Checks that:
     * 1. The transition is allowed per the state machine
     * 2. Required fields are present for the target status
     * 3. archiefnominatie matches the target status
     *
     * @param array  $tmlo     The full TMLO metadata (with new archiefstatus)
     * @param string $oldStatus The current/old archiefstatus
     *
     * @return array Array of validation errors (empty if valid)
     */
    public function validateStatusTransition(array $tmlo, string $oldStatus): array
    {
        $errors    = [];
        $newStatus = ($tmlo['archiefstatus'] ?? null);

        // No change in status.
        if ($newStatus === null || $newStatus === $oldStatus) {
            return $errors;
        }

        // Check if the transition is allowed.
        $allowedTargets = (self::VALID_TRANSITIONS[$oldStatus] ?? []);
        if (in_array($newStatus, $allowedTargets, true) === false) {
            $errors[] = "Transition from '{$oldStatus}' to '{$newStatus}' is not allowed. "
                ."Allowed transitions from '{$oldStatus}': "
                .(empty($allowedTargets) === true ? 'none (terminal state)' : implode(', ', $allowedTargets));
            return $errors;
        }

        // Validate required fields for transfer (overgebracht).
        if ($newStatus === self::ARCHIEFSTATUS_OVERGEBRACHT) {
            $requiredFields = ['archiefactiedatum', 'classificatie', 'archiefnominatie'];
            foreach ($requiredFields as $field) {
                if (($tmlo[$field] ?? null) === null || $tmlo[$field] === '') {
                    $errors[] = "Field '{$field}' is required for transition to 'overgebracht'";
                }
            }

            if (($tmlo['archiefnominatie'] ?? null) !== self::ARCHIEFNOMINATIE_BLIJVEND_BEWAREN) {
                $errors[] = "archiefnominatie must be 'blijvend_bewaren' for transition to 'overgebracht'";
            }
        }

        // Validate required fields for destruction (vernietigd).
        if ($newStatus === self::ARCHIEFSTATUS_VERNIETIGD) {
            $requiredFields = ['archiefactiedatum', 'classificatie', 'archiefnominatie', 'vernietigingsCategorie'];
            foreach ($requiredFields as $field) {
                if (($tmlo[$field] ?? null) === null || $tmlo[$field] === '') {
                    $errors[] = "Field '{$field}' is required for transition to 'vernietigd'";
                }
            }

            if (($tmlo['archiefnominatie'] ?? null) !== self::ARCHIEFNOMINATIE_VERNIETIGEN) {
                $errors[] = "archiefnominatie must be 'vernietigen' for transition to 'vernietigd'";
            }
        }

        return $errors;
    }//end validateStatusTransition()


    /**
     * Generate MDTO-compliant XML for a single object.
     *
     * @param ObjectEntity $object The object to export
     *
     * @return string The MDTO XML string
     *
     * @throws InvalidArgumentException If the object has no TMLO metadata
     */
    public function generateMdtoXml(ObjectEntity $object): string
    {
        $tmlo = $object->getTmlo();
        if (is_array($tmlo) === false || empty($tmlo) === true) {
            throw new InvalidArgumentException(
                'Object '.$object->getUuid().' has no TMLO metadata. MDTO export requires TMLO metadata.'
            );
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $this->createMdtoObjectElement($dom, $object, $tmlo);
        $dom->appendChild($root);

        return $dom->saveXML();
    }//end generateMdtoXml()


    /**
     * Generate MDTO-compliant XML for multiple objects.
     *
     * @param ObjectEntity[] $objects Array of objects to export
     *
     * @return string The MDTO XML string with multiple objects
     */
    public function generateBatchMdtoXml(array $objects): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $collection = $dom->createElementNS(self::MDTO_NAMESPACE, 'mdto:informatieobjecten');
        $dom->appendChild($collection);

        foreach ($objects as $object) {
            $tmlo = $object->getTmlo();
            if (is_array($tmlo) === false || empty($tmlo) === true) {
                continue;
            }

            $element = $this->createMdtoObjectElement($dom, $object, $tmlo);
            $collection->appendChild($element);
        }

        return $dom->saveXML();
    }//end generateBatchMdtoXml()


    /**
     * Create a single MDTO object XML element.
     *
     * @param DOMDocument  $dom    The DOM document
     * @param ObjectEntity $object The object entity
     * @param array        $tmlo   The TMLO metadata array
     *
     * @return DOMElement The MDTO object element
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function createMdtoObjectElement(DOMDocument $dom, ObjectEntity $object, array $tmlo): DOMElement
    {
        $root = $dom->createElementNS(self::MDTO_NAMESPACE, 'mdto:informatieobject');

        // Identificatie.
        $idElement = $dom->createElementNS(self::MDTO_NAMESPACE, 'mdto:identificatie');
        $idKenmerk = $dom->createElementNS(
            self::MDTO_NAMESPACE,
            'mdto:identificatieKenmerk',
            $this->xmlEscape($object->getUuid() ?? '')
        );
        $idBron = $dom->createElementNS(self::MDTO_NAMESPACE, 'mdto:identificatieBron', 'OpenRegister');
        $idElement->appendChild($idKenmerk);
        $idElement->appendChild($idBron);
        $root->appendChild($idElement);

        // Naam.
        $naam = $dom->createElementNS(
            self::MDTO_NAMESPACE,
            'mdto:naam',
            $this->xmlEscape($object->getName() ?? $object->getUuid() ?? '')
        );
        $root->appendChild($naam);

        // TMLO fields.
        if (($tmlo['classificatie'] ?? null) !== null) {
            $classEl = $dom->createElementNS(self::MDTO_NAMESPACE, 'mdto:classificatie');
            $classCode = $dom->createElementNS(
                self::MDTO_NAMESPACE,
                'mdto:classificatieCode',
                $this->xmlEscape($tmlo['classificatie'])
            );
            $classEl->appendChild($classCode);
            $root->appendChild($classEl);
        }

        if (($tmlo['archiefnominatie'] ?? null) !== null) {
            $root->appendChild(
                $dom->createElementNS(
                    self::MDTO_NAMESPACE,
                    'mdto:waarpinaering',
                    $this->mapArchiefnominatie($tmlo['archiefnominatie'])
                )
            );
        }

        if (($tmlo['archiefactiedatum'] ?? null) !== null) {
            $root->appendChild(
                $dom->createElementNS(
                    self::MDTO_NAMESPACE,
                    'mdto:archiefactiedatum',
                    $this->xmlEscape($tmlo['archiefactiedatum'])
                )
            );
        }

        if (($tmlo['archiefstatus'] ?? null) !== null) {
            $root->appendChild(
                $dom->createElementNS(
                    self::MDTO_NAMESPACE,
                    'mdto:archiefstatus',
                    $this->mapArchiefstatus($tmlo['archiefstatus'])
                )
            );
        }

        if (($tmlo['bewaarTermijn'] ?? null) !== null) {
            $root->appendChild(
                $dom->createElementNS(
                    self::MDTO_NAMESPACE,
                    'mdto:bewaartermijn',
                    $this->xmlEscape($tmlo['bewaarTermijn'])
                )
            );
        }

        if (($tmlo['vernietigingsCategorie'] ?? null) !== null) {
            $root->appendChild(
                $dom->createElementNS(
                    self::MDTO_NAMESPACE,
                    'mdto:vernietigingsCategorie',
                    $this->xmlEscape($tmlo['vernietigingsCategorie'])
                )
            );
        }

        return $root;
    }//end createMdtoObjectElement()


    /**
     * Map TMLO archiefnominatie to MDTO waardering value.
     *
     * @param string $nominatie The TMLO archiefnominatie value
     *
     * @return string The MDTO waardering value
     */
    private function mapArchiefnominatie(string $nominatie): string
    {
        $mapping = [
            self::ARCHIEFNOMINATIE_BLIJVEND_BEWAREN => 'bewaren',
            self::ARCHIEFNOMINATIE_VERNIETIGEN      => 'vernietigen',
        ];

        return ($mapping[$nominatie] ?? $nominatie);
    }//end mapArchiefnominatie()


    /**
     * Map TMLO archiefstatus to MDTO archiefstatus value.
     *
     * @param string $status The TMLO archiefstatus value
     *
     * @return string The MDTO archiefstatus value
     */
    private function mapArchiefstatus(string $status): string
    {
        $mapping = [
            self::ARCHIEFSTATUS_ACTIEF        => 'in bewerking',
            self::ARCHIEFSTATUS_SEMI_STATISCH  => 'afgesloten',
            self::ARCHIEFSTATUS_OVERGEBRACHT   => 'overgebracht',
            self::ARCHIEFSTATUS_VERNIETIGD     => 'vernietigd',
        ];

        return ($mapping[$status] ?? $status);
    }//end mapArchiefstatus()


    /**
     * Escape a string for safe XML inclusion.
     *
     * @param string $value The value to escape
     *
     * @return string The escaped value
     */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }//end xmlEscape()


}//end class
