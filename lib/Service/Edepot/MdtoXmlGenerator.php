<?php

/**
 * OpenRegister MDTO XML Generator
 *
 * Generates MDTO-compliant XML metadata for objects eligible for e-Depot transfer.
 * Conforms to the MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie)
 * schema version 1.0 or later.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Generator for MDTO-compliant XML metadata documents.
 *
 * Produces valid XML conforming to the MDTO schema with the correct namespace.
 * Handles mandatory elements (identificatie, naam, waardering, bewaartermijn,
 * informatiecategorie, archiefvormer) and optional elements (bestand references).
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MdtoXmlGenerator
{

    /**
     * MDTO namespace URI.
     */
    public const MDTO_NAMESPACE = 'https://www.nationaalarchief.nl/mdto';

    /**
     * MDTO namespace prefix.
     */
    public const MDTO_PREFIX = 'mdto';

    /**
     * Mapping from archiefnominatie values to MDTO waardering values.
     *
     * @var array<string, string>
     */
    private const WAARDERING_MAP = [
        'vernietigen'      => 'vernietigen',
        'bewaren'          => 'bewaren',
        'nog_niet_bepaald' => 'nog niet bepaald',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app configuration for organisation settings.
     * @param LoggerInterface $logger    Logger for error and info messages.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate MDTO XML for an object.
     *
     * @param ObjectEntity $object The object to generate XML for.
     * @param array        $files  Associated file metadata (name, size, format, checksum).
     *
     * @return string The generated MDTO XML string.
     *
     * @throws InvalidArgumentException If required fields are missing.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-20
     */
    public function generate(ObjectEntity $object, array $files=[]): string
    {
        $retention = ($object->getRetention() ?? []);

        $this->validateRequiredFields(object: $object, retention: $retention);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':informatieobject');
        $dom->appendChild($root);

        $this->addIdentificatie(dom: $dom, parent: $root, object: $object);
        $this->addNaam(dom: $dom, parent: $root, object: $object);
        $this->addWaardering(dom: $dom, parent: $root, retention: $retention);
        $this->addBewaartermijn(dom: $dom, parent: $root, retention: $retention);
        $this->addInformatiecategorie(dom: $dom, parent: $root, retention: $retention);
        $this->addArchiefvormer(dom: $dom, parent: $root);

        if (empty($retention['toelichting']) === false) {
            $this->addTextElement(dom: $dom, parent: $root, name: 'toelichting', content: $retention['toelichting']);
        }

        foreach ($files as $file) {
            $this->addBestand(dom: $dom, parent: $root, file: $file);
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new InvalidArgumentException('Failed to generate MDTO XML');
        }

        return $xml;
    }//end generate()

    /**
     * Validate that all required MDTO fields are present.
     *
     * @param ObjectEntity        $object    The object to validate.
     * @param array<string,mixed> $retention The retention metadata.
     *
     * @return void
     *
     * @throws InvalidArgumentException If required fields are missing.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-20
     */
    private function validateRequiredFields(ObjectEntity $object, array $retention): void
    {
        $missing = [];

        if (empty($object->getUuid()) === true) {
            $missing[] = 'uuid';
        }

        if (empty($retention['archiefnominatie']) === true) {
            $missing[] = 'retention.archiefnominatie';
        }

        if (empty($retention['bewaartermijn']) === true) {
            $missing[] = 'retention.bewaartermijn';
        }

        $archiefvormer = $this->appConfig->getValueString('openregister', 'organisation_identifier', '');
        if (empty($archiefvormer) === true) {
            $missing[] = 'app_setting:organisation_identifier';
        }

        if (empty($missing) === false) {
            $missingStr = implode(', ', $missing);
            $this->logger->error(
                message: '[MdtoXmlGenerator] Missing required MDTO fields: '.$missingStr,
                context: ['objectUuid' => $object->getUuid()]
            );
            throw new InvalidArgumentException(
                'Missing required MDTO fields for object '.$object->getUuid().': '.$missingStr
            );
        }
    }//end validateRequiredFields()

    /**
     * Add the identificatie element to the XML document.
     *
     * @param DOMDocument  $dom    The DOM document.
     * @param DOMElement   $parent The parent element.
     * @param ObjectEntity $object The source object.
     *
     * @return void
     */
    private function addIdentificatie(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void
    {
        $identificatie = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':identificatie');

        $kenmerk = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':identificatieKenmerk');
        $kenmerk->textContent = $object->getUuid();
        $identificatie->appendChild($kenmerk);

        $bron           = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':identificatieBron');
        $organisationId = $this->appConfig->getValueString('openregister', 'organisation_identifier', 'OpenRegister');
        $bron->textContent = $organisationId;
        $identificatie->appendChild($bron);

        $parent->appendChild($identificatie);
    }//end addIdentificatie()

    /**
     * Add the naam element to the XML document.
     *
     * @param DOMDocument  $dom    The DOM document.
     * @param DOMElement   $parent The parent element.
     * @param ObjectEntity $object The source object.
     *
     * @return void
     */
    private function addNaam(DOMDocument $dom, DOMElement $parent, ObjectEntity $object): void
    {
        $data  = ($object->getObject() ?? []);
        $title = ($data['title'] ?? $data['naam'] ?? $data['name'] ?? $object->getUuid());
        $this->addTextElement(dom: $dom, parent: $parent, name: 'naam', content: (string) $title);
    }//end addNaam()

    /**
     * Add the waardering element to the XML document.
     *
     * @param DOMDocument         $dom       The DOM document.
     * @param DOMElement          $parent    The parent element.
     * @param array<string,mixed> $retention The retention metadata.
     *
     * @return void
     */
    private function addWaardering(DOMDocument $dom, DOMElement $parent, array $retention): void
    {
        $nominatie  = ($retention['archiefnominatie'] ?? '');
        $waardering = (self::WAARDERING_MAP[$nominatie] ?? $nominatie);
        $this->addTextElement(dom: $dom, parent: $parent, name: 'waardering', content: $waardering);
    }//end addWaardering()

    /**
     * Add the bewaartermijn element to the XML document.
     *
     * @param DOMDocument         $dom       The DOM document.
     * @param DOMElement          $parent    The parent element.
     * @param array<string,mixed> $retention The retention metadata.
     *
     * @return void
     */
    private function addBewaartermijn(DOMDocument $dom, DOMElement $parent, array $retention): void
    {
        $bewaartermijn = ($retention['bewaartermijn'] ?? '');
        $this->addTextElement(dom: $dom, parent: $parent, name: 'bewaartermijn', content: (string) $bewaartermijn);
    }//end addBewaartermijn()

    /**
     * Add the informatiecategorie element to the XML document.
     *
     * @param DOMDocument         $dom       The DOM document.
     * @param DOMElement          $parent    The parent element.
     * @param array<string,mixed> $retention The retention metadata.
     *
     * @return void
     */
    private function addInformatiecategorie(DOMDocument $dom, DOMElement $parent, array $retention): void
    {
        $classificatie = ($retention['classificatie'] ?? 'onbekend');
        $this->addTextElement(dom: $dom, parent: $parent, name: 'informatiecategorie', content: (string) $classificatie);
    }//end addInformatiecategorie()

    /**
     * Add the archiefvormer element to the XML document.
     *
     * @param DOMDocument $dom    The DOM document.
     * @param DOMElement  $parent The parent element.
     *
     * @return void
     */
    private function addArchiefvormer(DOMDocument $dom, DOMElement $parent): void
    {
        $organisationId   = $this->appConfig->getValueString('openregister', 'organisation_identifier', 'OpenRegister');
        $organisationName = $this->appConfig->getValueString('openregister', 'organisation_name', 'OpenRegister');

        $archiefvormer = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':archiefvormer');

        $verwijzing = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':verwijzingNaam');
        $verwijzing->textContent = $organisationName;
        $archiefvormer->appendChild($verwijzing);

        $id = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':verwijzingIdentificatie');

        $kenmerk = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':identificatieKenmerk');
        $kenmerk->textContent = $organisationId;
        $id->appendChild($kenmerk);

        $bron = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':identificatieBron');
        $bron->textContent = 'OpenRegister';
        $id->appendChild($bron);

        $archiefvormer->appendChild($id);
        $parent->appendChild($archiefvormer);
    }//end addArchiefvormer()

    /**
     * Add a bestand (file) element to the XML document.
     *
     * @param DOMDocument                                                      $dom    The DOM document.
     * @param DOMElement                                                       $parent The parent element.
     * @param array{name: string, size: int, format: string, checksum: string} $file   The file metadata.
     *
     * @return void
     */
    private function addBestand(DOMDocument $dom, DOMElement $parent, array $file): void
    {
        $bestand = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':bestand');

        $this->addTextElement(dom: $dom, parent: $bestand, name: 'naam', content: $file['name']);
        $this->addTextElement(dom: $dom, parent: $bestand, name: 'omvang', content: (string) $file['size']);
        $this->addTextElement(dom: $dom, parent: $bestand, name: 'bestandsformaat', content: $file['format']);

        $checksumElement = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':checksum');

        $algoritme = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':checksumAlgoritme');
        $algoritme->textContent = 'SHA-256';
        $checksumElement->appendChild($algoritme);

        $waarde = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':checksumWaarde');
        $waarde->textContent = $file['checksum'];
        $checksumElement->appendChild($waarde);

        $bestand->appendChild($checksumElement);
        $parent->appendChild($bestand);
    }//end addBestand()

    /**
     * Add a simple text element to the XML document.
     *
     * @param DOMDocument $dom     The DOM document.
     * @param DOMElement  $parent  The parent element.
     * @param string      $name    The element name (without namespace prefix).
     * @param string      $content The text content.
     *
     * @return void
     */
    private function addTextElement(DOMDocument $dom, DOMElement $parent, string $name, string $content): void
    {
        $element = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX.':'.$name);
        $element->textContent = $content;
        $parent->appendChild($element);
    }//end addTextElement()
}//end class
