<?php

/**
 * OpenRegister Archiefactiedatum Calculator
 *
 * Calculates the archive action date (archiefactiedatum) using configurable
 * derivation methods (afleidingswijzen) as defined by the ZGW API standard.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-6
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Calculator for archive action dates using configurable derivation methods.
 *
 * Supports three afleidingswijzen as defined by the ZGW API standard:
 * - afgehandeld: derives from case closure date
 * - eigenschap: derives from a named property value on the object
 * - termijn: derives from closure date plus a process term (procestermijn)
 *
 * @psalm-suppress UnusedClass
 */
class ArchiefactiedatumCalculator
{

    /**
     * Supported derivation methods.
     */
    private const AFLEIDINGSWIJZE_AFGEHANDELD = 'afgehandeld';
    private const AFLEIDINGSWIJZE_EIGENSCHAP  = 'eigenschap';
    private const AFLEIDINGSWIJZE_TERMIJN     = 'termijn';

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger for error and info messages.
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }//end __construct()

    /**
     * Calculate the archiefactiedatum based on the given configuration.
     *
     * @param array<string, mixed> $archiveConfig The schema's archive configuration containing:
     *                                            - afleidingswijze: string (afgehandeld|eigenschap|termijn)
     *                                            - bewaartermijn: string ISO 8601 duration (e.g. P5Y)
     *                                            - eigenschap: string property name (for eigenschap method)
     *                                            - procestermijn: string ISO 8601 duration (for termijn method)
     * @param array<string, mixed> $objectData    The object's data array.
     * @param DateTime|null        $closureDate   The case closure date (for afgehandeld and termijn methods).
     *
     * @return DateTime|null The calculated archiefactiedatum, or null if calculation is not possible.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-6
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-2
     */
    public function calculate(array $archiveConfig, array $objectData, ?DateTime $closureDate=null): ?DateTime
    {
        $afleidingswijze = $archiveConfig['afleidingswijze'] ?? null;
        $bewaartermijn   = $archiveConfig['bewaartermijn'] ?? null;

        if ($afleidingswijze === null || $bewaartermijn === null) {
            $this->logger->debug(
                message: '[ArchiefactiedatumCalculator] Missing afleidingswijze or bewaartermijn in archive config',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'archiveConfig' => $archiveConfig,
                ]
            );
            return null;
        }

        try {
            $duration = new DateInterval($bewaartermijn);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ArchiefactiedatumCalculator] Invalid bewaartermijn format: '.$bewaartermijn,
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        }

        $brondatum = $this->determineBrondatum(
            afleidingswijze: $afleidingswijze,
            archiveConfig: $archiveConfig,
            objectData: $objectData,
            closureDate: $closureDate
        );

        if ($brondatum === null) {
            $this->logger->debug(
                message: '[ArchiefactiedatumCalculator] Could not determine brondatum',
                context: [
                    'file'            => __FILE__,
                    'line'            => __LINE__,
                    'afleidingswijze' => $afleidingswijze,
                ]
            );
            return null;
        }

        $archiefactiedatum = clone $brondatum;
        $archiefactiedatum->add($duration);

        $this->logger->info(
            message: '[ArchiefactiedatumCalculator] Calculated archiefactiedatum',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'afleidingswijze'   => $afleidingswijze,
                'brondatum'         => $brondatum->format('Y-m-d'),
                'bewaartermijn'     => $bewaartermijn,
                'archiefactiedatum' => $archiefactiedatum->format('Y-m-d'),
            ]
        );

        return $archiefactiedatum;
    }//end calculate()

    /**
     * Determine the base date (brondatum) for the calculation.
     *
     * @param string               $afleidingswijze The derivation method.
     * @param array<string, mixed> $archiveConfig   The archive configuration.
     * @param array<string, mixed> $objectData      The object data.
     * @param DateTime|null        $closureDate     The case closure date.
     *
     * @return DateTime|null The determined base date.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-6
     */
    private function determineBrondatum(
        string $afleidingswijze,
        array $archiveConfig,
        array $objectData,
        ?DateTime $closureDate
    ): ?DateTime {
        switch ($afleidingswijze) {
            case self::AFLEIDINGSWIJZE_AFGEHANDELD:
                return $this->brondatumFromClosure(closureDate: $closureDate);

            case self::AFLEIDINGSWIJZE_EIGENSCHAP:
                return $this->brondatumFromProperty(archiveConfig: $archiveConfig, objectData: $objectData);

            case self::AFLEIDINGSWIJZE_TERMIJN:
                return $this->brondatumFromTermijn(archiveConfig: $archiveConfig, closureDate: $closureDate);

            default:
                $this->logger->warning(
                message: '[ArchiefactiedatumCalculator] Unknown afleidingswijze: '.$afleidingswijze,
                context: [
                    'file'            => __FILE__,
                    'line'            => __LINE__,
                    'afleidingswijze' => $afleidingswijze,
                ]
                );
                return null;
        }//end switch
    }//end determineBrondatum()

    /**
     * Get brondatum from case closure date (afgehandeld method).
     *
     * @param DateTime|null $closureDate The case closure date.
     *
     * @return DateTime|null The closure date or null if not provided.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-6
     */
    private function brondatumFromClosure(?DateTime $closureDate): ?DateTime
    {
        if ($closureDate === null) {
            $this->logger->debug(
                message: '[ArchiefactiedatumCalculator] No closure date provided for afgehandeld method',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        return clone $closureDate;
    }//end brondatumFromClosure()

    /**
     * Get brondatum from a named property on the object (eigenschap method).
     *
     * @param array<string, mixed> $archiveConfig The archive configuration containing 'eigenschap' key.
     * @param array<string, mixed> $objectData    The object data.
     *
     * @return DateTime|null The date from the property value, or null.
     */
    private function brondatumFromProperty(array $archiveConfig, array $objectData): ?DateTime
    {
        $propertyName = $archiveConfig['eigenschap'] ?? null;
        if ($propertyName === null) {
            $this->logger->warning(
                message: '[ArchiefactiedatumCalculator] No eigenschap configured for eigenschap method',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        $propertyValue = $objectData[$propertyName] ?? null;
        if ($propertyValue === null) {
            $this->logger->debug(
                message: '[ArchiefactiedatumCalculator] Property value not found on object',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'propertyName' => $propertyName,
                ]
            );
            return null;
        }

        try {
            return new DateTime($propertyValue);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ArchiefactiedatumCalculator] Invalid date in property: '.$propertyName,
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'propertyValue' => $propertyValue,
                    'exception'     => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end brondatumFromProperty()

    /**
     * Get brondatum from closure date plus process term (termijn method).
     *
     * @param array<string, mixed> $archiveConfig The archive configuration containing 'procestermijn' key.
     * @param DateTime|null        $closureDate   The case closure date.
     *
     * @return DateTime|null The base date (closure + procestermijn), or null.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-6
     */
    private function brondatumFromTermijn(array $archiveConfig, ?DateTime $closureDate): ?DateTime
    {
        if ($closureDate === null) {
            $this->logger->debug(
                message: '[ArchiefactiedatumCalculator] No closure date provided for termijn method',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        $procestermijn = $archiveConfig['procestermijn'] ?? null;
        if ($procestermijn === null) {
            $this->logger->warning(
                message: '[ArchiefactiedatumCalculator] No procestermijn configured for termijn method',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        try {
            $interval  = new DateInterval($procestermijn);
            $brondatum = clone $closureDate;
            $brondatum->add($interval);
            return $brondatum;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ArchiefactiedatumCalculator] Invalid procestermijn format: '.$procestermijn,
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end brondatumFromTermijn()
}//end class
