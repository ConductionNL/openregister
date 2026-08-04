<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Repair\SeedVocabularyRegister}
 * (skos-concept-registers, SKOS-003).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenRegister\Repair\SeedVocabularyRegister;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SeedVocabularyRegisterTest extends TestCase
{

    private ConfigurationService&MockObject $configurationService;

    private VocabularyImportService&MockObject $vocabularyImportService;

    private IAppManager&MockObject $appManager;

    private IOutput&MockObject $output;

    private SeedVocabularyRegister $repairStep;

    /**
     * Real path of the openregister app checkout (repo root).
     *
     * @var string
     */
    private string $appPath;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->appPath = realpath(__DIR__.'/../../..');

        $this->configurationService    = $this->createMock(ConfigurationService::class);
        $this->vocabularyImportService = $this->createMock(VocabularyImportService::class);
        $this->appManager              = $this->createMock(IAppManager::class);
        $this->output                  = $this->createMock(IOutput::class);
        $logger                        = $this->createMock(LoggerInterface::class);

        $this->appManager->method('getAppPath')->with('openregister')->willReturn($this->appPath);

        $this->repairStep = new SeedVocabularyRegister(
            configurationService: $this->configurationService,
            importService: $this->vocabularyImportService,
            appManager: $this->appManager,
            logger: $logger
        );
    }//end setUp()

    /**
     * @return void
     */
    public function testImplementsIRepairStep(): void
    {
        $this->assertInstanceOf(IRepairStep::class, $this->repairStep);
    }//end testImplementsIRepairStep()

    /**
     * @return void
     */
    public function testGetNameReturnsDescriptiveString(): void
    {
        $this->assertSame(
            'Import OpenRegister vocabulary register and seed bundled TOOI Woo value lists',
            $this->repairStep->getName()
        );
    }//end testGetNameReturnsDescriptiveString()

    /**
     * The register descriptor is imported with the dedicated app id, force=false
     * (re-runs are no-ops, mirroring ImportTrustConfigurationRegister).
     *
     * @return void
     */
    public function testRunImportsTheVocabularyRegisterDescriptor(): void
    {
        $this->vocabularyImportService->method('importJsonLd')->willReturn([
            'scheme'    => 'https://example.org/scheme',
            'created'   => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'deprecated'=> 0,
        ]);

        $this->configurationService->expects($this->once())
            ->method('importFromApp')
            ->with(
                $this->equalTo('openregister.vocabulary'),
                $this->callback(function (array $data): bool {
                    $schemas = ($data['components']['schemas'] ?? []);
                    return isset($schemas['conceptScheme']) === true && isset($schemas['concept']) === true;
                }),
                $this->equalTo('1.0.0'),
                $this->equalTo(false)
            );

        $this->repairStep->run($this->output);
    }//end testRunImportsTheVocabularyRegisterDescriptor()

    /**
     * Both bundled TOOI fixtures are seeded through the idempotent importer,
     * and the informatiecategorieën fixture carries exactly the 17 Woo
     * categories with TOOI kern-thesaurus URIs.
     *
     * @return void
     */
    public function testRunSeedsBothTooiFixturesWithSeventeenInformatiecategorieen(): void
    {
        $seenGraphs = [];

        $this->vocabularyImportService->expects($this->exactly(2))
            ->method('importJsonLd')
            ->willReturnCallback(function (array $jsonLd) use (&$seenGraphs): array {
                $seenGraphs[] = $jsonLd;
                return [
                    'scheme'    => ($jsonLd['@graph'][0]['@id'] ?? ''),
                    'created'   => (count($jsonLd['@graph']) - 1),
                    'updated'   => 0,
                    'unchanged' => 0,
                    'deprecated'=> 0,
                ];
            });

        $this->repairStep->run($this->output);

        $this->assertCount(2, $seenGraphs);

        $informatiecategorieen = null;
        foreach ($seenGraphs as $graph) {
            if (($graph['@graph'][0]['dct:title'] ?? '') === 'Woo-informatiecategorieën') {
                $informatiecategorieen = $graph;
            }
        }

        $this->assertNotNull($informatiecategorieen, 'The informatiecategorieën fixture must be one of the two seeded graphs');

        $concepts = array_filter(
            $informatiecategorieen['@graph'],
            static fn (array $node): bool => ($node['@type'] ?? '') === 'skos:Concept'
        );
        $this->assertCount(17, $concepts, 'Fresh install must serve all 17 Woo informatiecategorieën');

        foreach ($concepts as $concept) {
            $this->assertStringStartsWith(
                'https://identifier.overheid.nl/tooi/def/thes/kern/c_',
                $concept['@id'],
                'Every informatiecategorie must carry a TOOI kern-thesaurus URI'
            );
            $this->assertArrayHasKey('nl', $this->firstPrefLabel($concept), 'Every concept needs a Dutch prefLabel');
        }
    }//end testRunSeedsBothTooiFixturesWithSeventeenInformatiecategorieen()

    /**
     * Re-running the repair step is safe: no exception, and the register
     * import + fixture seed calls happen the same way each time (repeat-safe
     * per SKOS-003; the underlying no-op behaviour is proven by
     * VocabularyImportServiceTest).
     *
     * @return void
     */
    public function testRunTwiceIsRepeatSafe(): void
    {
        $this->vocabularyImportService->method('importJsonLd')->willReturn([
            'scheme'    => 'https://example.org/scheme',
            'created'   => 0,
            'updated'   => 0,
            'unchanged' => 17,
            'deprecated'=> 0,
        ]);

        $this->configurationService->expects($this->exactly(2))->method('importFromApp');

        $this->repairStep->run($this->output);
        $this->repairStep->run($this->output);
    }//end testRunTwiceIsRepeatSafe()

    /**
     * A failure in the register import must not prevent the fixture seed
     * from being attempted (never throws — fail-soft repair step).
     *
     * @return void
     */
    public function testRegisterImportFailureDoesNotAbortFixtureSeeding(): void
    {
        $this->configurationService->method('importFromApp')->willThrowException(new \RuntimeException('boom'));
        $this->vocabularyImportService->expects($this->exactly(2))->method('importJsonLd')->willReturn([
            'scheme'    => 'https://example.org/scheme',
            'created'   => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'deprecated'=> 0,
        ]);

        $this->repairStep->run($this->output);
    }//end testRegisterImportFailureDoesNotAbortFixtureSeeding()

    /**
     * @param array<string, mixed> $conceptNode A parsed skos:Concept node.
     *
     * @return array<string, string>
     */
    private function firstPrefLabel(array $conceptNode): array
    {
        $raw = ($conceptNode['skos:prefLabel'] ?? []);
        $map = [];
        foreach ($raw as $literal) {
            $map[($literal['@language'] ?? 'nl')] = $literal['@value'];
        }

        return $map;
    }//end firstPrefLabel()
}//end class
