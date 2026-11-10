<?php

/**
 * Unit test for MdtoPreconditions' refusals: one unmapped element is named in
 * the singular, and a generator refuses an object without uuid or name or with
 * a retention period that is no xsd:duration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\MdtoMappingResolver;
use OCA\OpenRegister\Service\Edepot\MdtoBestandGenerator;
use OCA\OpenRegister\Service\Edepot\MdtoInputReaders;
use OCA\OpenRegister\Service\Edepot\MdtoPreconditions;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoPreconditions
 * @uses   \OCA\OpenRegister\Service\Edepot\MdtoInputReaders
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class MdtoPreconditionsRefusalTest extends TestCase {

	/**
	 * Preconditions over fixed facts.
	 *
	 * @param array<string, mixed> $facts    The core facts.
	 * @param string               $name     The object's name.
	 * @param array<int, string>   $unfilled The unfilled mandatory mapped elements.
	 *
	 * @return MdtoPreconditions
	 */
	private function preconditions(array $facts, string $name='Dossier', array $unfilled=[]): MdtoPreconditions {
		$source = $this->createMock(MdtoSourceReader::class);
		$source->method('coreFacts')->willReturn($facts);
		$source->method('name')->willReturn($name);
		$bestand = $this->createMock(MdtoBestandGenerator::class);
		$bestand->method('missingInputs')->willReturn([]);
		$resolver = $this->createMock(MdtoMappingResolver::class);
		$resolver->method('unfilledMandatoryElements')->willReturn($unfilled);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('org-1');

		return new MdtoPreconditions(new MdtoInputReaders($source, $bestand, $resolver), new \OCA\OpenRegister\Service\Configuration\AppSettingsAccess($config, $this->createMock(\OCP\App\IAppManager::class), $this->createMock(LoggerInterface::class)));
	}//end preconditions()

	/**
	 * An object with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $uuid='u-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

	/**
	 * The refusal a call makes, or '' when it allows.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		return '';
	}//end refusal()

	/**
	 * One unmapped element is named in the singular; two in the plural; none allows the transfer.
	 *
	 * @return void
	 */
	public function testUnmappedElementsAreNamed(): void {
		$facts = ['retentionPeriod' => 'P20Y', 'appraisal' => 'blijvend_bewaren'];

		$this->assertSame(
			'Cannot transfer object u-1 to an e-Depot: its schema declares an MDTO mapping that does not fill the mandatory element archiefvormer. Map it in x-openregister-mdto-mapping, or the e-Depot will refuse the package.',
			$this->refusal(fn () => $this->preconditions(facts: $facts, unfilled: ['archiefvormer'])->assertTransferPreconditions(self::object()))
		);
		$this->assertStringContainsString(
			'these mandatory elements: archiefvormer, naam. Map them in',
			$this->refusal(fn () => $this->preconditions(facts: $facts, unfilled: ['archiefvormer', 'naam'])->assertTransferPreconditions(self::object()))
		);
		$this->assertSame('', $this->refusal(fn () => $this->preconditions(facts: $facts)->assertTransferPreconditions(self::object())));
	}//end testUnmappedElementsAreNamed()

	/**
	 * A generator refuses an object without uuid, without name, or with a period that is no duration, naming each.
	 *
	 * @return void
	 */
	public function testTheGeneratorNamesWhatIsMissing(): void {
		$facts = ['retentionPeriod' => 'P20Y', 'appraisal' => 'blijvend_bewaren'];

		$this->assertSame('', $this->refusal(fn () => $this->preconditions(facts: $facts)->assertGeneratorPreconditions(self::object(), [])));
		$this->assertStringContainsString('uuid', $this->refusal(fn () => $this->preconditions(facts: $facts)->assertGeneratorPreconditions(self::object(uuid: ''), [])));
		$this->assertStringContainsString('naam', $this->refusal(fn () => $this->preconditions(facts: $facts, name: '')->assertGeneratorPreconditions(self::object(), [])));
		$this->assertStringContainsString(
			'bewaartermijn (an ISO-8601 / xsd:duration such as P20Y)',
			$this->refusal(fn () => $this->preconditions(facts: ['retentionPeriod' => 20] + $facts)->assertGeneratorPreconditions(self::object(), []))
		);
		$this->assertStringNotContainsString(
			'bewaartermijn',
			$this->refusal(fn () => $this->preconditions(facts: ['retentionPeriod' => 'P1Y2M3DT4H'] + $facts, name: '')->assertGeneratorPreconditions(self::object(), []))
		);
	}//end testTheGeneratorNamesWhatIsMissing()
}//end class
