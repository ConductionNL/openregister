<?php

/**
 * DocxRunGroupTest
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Anonymisation;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PhpOffice\PhpWord\Element\Text;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Covers the docx run-group redaction that replaced the per-element
 * `str_ireplace` loop.
 *
 * The headline case — an entity split across two `<w:r>` runs — was
 * UNREACHABLE before this change and was documented in-tree as a known
 * concession. Word produces such splits routinely at formatting, spell-check
 * and `rsid` boundaries, so this is the common case, not an edge case.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class DocxRunGroupTest extends TestCase
{


    /**
     * Build a handler with all collaborators mocked.
     *
     * @return DocumentProcessingHandler
     */
    private function makeHandler(): DocumentProcessingHandler
    {
        return new DocumentProcessingHandler(
            rootFolder: $this->createMock(IRootFolder::class),
            userSession: $this->createMock(IUserSession::class),
            logger: $this->createMock(LoggerInterface::class),
            entityRelationMapper: $this->createMock(EntityRelationMapper::class),
            l10n: $this->createMock(IL10N::class)
        );
    }//end makeHandler()


    /**
     * Redact a group of runs and return their resulting text values.
     *
     * @param array<int, string>    $runs         The run texts, in document order.
     * @param array<string, string> $replacements Needle => placeholder.
     * @param array<string, string> $types        Needle => raw entity type.
     *
     * @return array<int, string>
     */
    private function redactRuns(array $runs, array $replacements, array $types=[]): array
    {
        $handler = $this->makeHandler();

        $typeMap = new ReflectionProperty(DocumentProcessingHandler::class, 'entityTypesByNeedle');
        $typeMap->setAccessible(true);
        $typeMap->setValue($handler, $types);

        $elements = [];
        foreach ($runs as $value) {
            $elements[] = new Text($value);
        }

        $method = new ReflectionMethod(DocumentProcessingHandler::class, 'applyToRunGroup');
        $method->setAccessible(true);
        $method->invoke($handler, $elements, $replacements);

        $out = [];
        foreach ($elements as $element) {
            $out[] = (string) $element->getText();
        }

        return $out;
    }//end redactRuns()


    /**
     * An entity split across two runs is redacted, the placeholder lands in the
     * run holding the start, and the trailing run keeps only its remainder.
     *
     * @return void
     */
    public function testEntitySplitAcrossTwoRunsIsRedacted(): void
    {
        $result = $this->redactRuns(
            runs: ['Jan', 'ssen belde gisteren'],
            replacements: ['Janssen' => '[PERSOON: 1]'],
            types: ['Janssen' => 'PERSON']
        );

        $this->assertSame(['[PERSOON: 1]', ' belde gisteren'], $result);
        $this->assertStringNotContainsString('Janssen', implode('', $result));
    }//end testEntitySplitAcrossTwoRunsIsRedacted()


    /**
     * The same split across three runs consumes the middle run entirely and
     * keeps it as an empty string rather than removing it.
     *
     * @return void
     */
    public function testEntitySplitAcrossThreeRunsEmptiesTheMiddle(): void
    {
        $result = $this->redactRuns(
            runs: ['Jan de ', 'Vries', ' was aanwezig'],
            replacements: ['Jan de Vries' => '[PERSOON: 1]'],
            types: ['Jan de Vries' => 'PERSON']
        );

        $this->assertSame(['[PERSOON: 1]', '', ' was aanwezig'], $result);
    }//end testEntitySplitAcrossThreeRunsEmptiesTheMiddle()


    /**
     * An entity wholly inside one run behaves as before.
     *
     * @return void
     */
    public function testEntityInsideOneRunIsUnaffectedByGrouping(): void
    {
        $result = $this->redactRuns(
            runs: ['Beste ', 'Jan Jansen', ', hierbij'],
            replacements: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame(['Beste ', '[PERSOON: 1]', ', hierbij'], $result);
    }//end testEntityInsideOneRunIsUnaffectedByGrouping()


    /**
     * Word boundaries now apply inside a run group, so a short free-text name
     * stops rewriting ordinary words. `str_ireplace` did not do this.
     *
     * @return void
     */
    public function testShortNameNoLongerRewritesAnOrdinaryWord(): void
    {
        $result = $this->redactRuns(
            runs: ['In Januari sprak ', 'Jan met de raad'],
            replacements: ['Jan' => '[PERSOON: 1]'],
            types: ['Jan' => 'PERSON']
        );

        $joined = implode('', $result);

        $this->assertStringContainsString('Januari', $joined, 'Januari must survive');
        $this->assertStringContainsString('[PERSOON: 1]', $joined, 'the standalone Jan is redacted');
        $this->assertSame('In Januari sprak [PERSOON: 1] met de raad', $joined);
    }//end testShortNameNoLongerRewritesAnOrdinaryWord()


    /**
     * The entity-type map reaches the boundary policy: an IP address split
     * across runs is matched, but not when it is a prefix of a longer address.
     *
     * @return void
     */
    public function testIpAddressPolicyAppliesAcrossRuns(): void
    {
        $matched = $this->redactRuns(
            runs: ['host 192.168.', '1.1 bereikt'],
            replacements: ['192.168.1.1' => '[IP-ADRES: 6]'],
            types: ['192.168.1.1' => 'IP_ADDRESS']
        );

        $this->assertSame('host [IP-ADRES: 6] bereikt', implode('', $matched));

        $rejected = $this->redactRuns(
            runs: ['host 192.168.', '1.10 bereikt'],
            replacements: ['192.168.1.1' => '[IP-ADRES: 6]'],
            types: ['192.168.1.1' => 'IP_ADDRESS']
        );

        $this->assertSame(
            'host 192.168.1.10 bereikt',
            implode('', $rejected),
            'the longer address must not be corrupted'
        );
    }//end testIpAddressPolicyAppliesAcrossRuns()


    /**
     * An emitted placeholder is never rescanned by a later needle.
     *
     * @return void
     */
    public function testEmittedPlaceholderIsNotRescanned(): void
    {
        $result = $this->redactRuns(
            runs: ['Dossier 1 van ', 'Jan Jansen'],
            replacements: ['Jan Jansen' => '[PERSOON: 1]', '1' => '[NUMMER: 2]'],
            types: ['Jan Jansen' => 'PERSON', '1' => 'POLISNUMMER']
        );

        $joined = implode('', $result);

        $this->assertSame('Dossier [NUMMER: 2] van [PERSOON: 1]', $joined);
        $this->assertSame(1, substr_count($joined, '[PERSOON: 1]'));
    }//end testEmittedPlaceholderIsNotRescanned()


    /**
     * An empty group, an empty replacement map and whitespace-only runs are all
     * no-ops rather than errors.
     *
     * @return void
     */
    public function testDegenerateInputsAreNoOps(): void
    {
        $this->assertSame([], $this->redactRuns(runs: [], replacements: ['Jan' => '[P: 1]']));
        $this->assertSame(['Jan Jansen'], $this->redactRuns(runs: ['Jan Jansen'], replacements: []));
        $this->assertSame(['   '], $this->redactRuns(runs: ['   '], replacements: ['Jan' => '[P: 1]']));
    }//end testDegenerateInputsAreNoOps()


    /**
     * Text outside any accepted range is preserved exactly, including a case
     * number the delimited-token policy must leave alone.
     *
     * @return void
     */
    public function testUncoveredTextIncludingCaseNumberIsPreserved(): void
    {
        $result = $this->redactRuns(
            runs: ['Kenmerk 2026-0012 betreft ', 'Jan Jansen op 2026.'],
            replacements: ['Jan Jansen' => '[PERSOON: 1]', '2026' => '[DATUM: 4]'],
            types: ['Jan Jansen' => 'PERSON', '2026' => 'DATE']
        );

        $joined = implode('', $result);

        $this->assertStringContainsString('Kenmerk 2026-0012', $joined, 'the case number must be untouched');
        $this->assertSame('Kenmerk 2026-0012 betreft [PERSOON: 1] op [DATUM: 4].', $joined);
    }//end testUncoveredTextIncludingCaseNumberIsPreserved()
}//end class
