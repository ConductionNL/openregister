<?php

/**
 * Golden test for the MdtoPreconditions refusals.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

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
 * Pins which objects may be TRANSFERRED and generated, and the exact refusal
 * for each that may not: no retention period, unfilled mandatory mapped
 * elements (one and several), and every missing generator input.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoPreconditions
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Configuration\AppSettingsAccess
 * @uses \OCA\OpenRegister\Service\Edepot\MdtoInputReaders
 */
class MdtoPreconditionsGoldenTest extends TestCase {

	/**
	 * Every recorded outcome, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Preconditions over fixed facts.
	 *
	 * @param array<string, mixed> $facts    The core facts.
	 * @param string               $name     The object's name.
	 * @param list<string>         $unfilled The unfilled mandatory mapped elements.
	 * @param list<string>         $missing  The missing file inputs.
	 * @param string               $orgId    The organisation identifier setting.
	 * @param array<mixed>|null    $restrict The use restriction.
	 *
	 * @return MdtoPreconditions
	 */
	private function preconditions(
		array $facts,
		string $name = 'Naam',
		array $unfilled = [],
		array $missing = [],
		string $orgId = 'org',
		?array $restrict = ['x']
	): MdtoPreconditions {
		$source = $this->createMock(MdtoSourceReader::class);
		$source->method('coreFacts')->willReturn($facts);
		$source->method('name')->willReturn($name);
		$source->method('useRestriction')->willReturn($restrict);

		$bestand = $this->createMock(MdtoBestandGenerator::class);
		$bestand->method('missingInputs')->willReturn($missing);

		$resolver = $this->createMock(MdtoMappingResolver::class);
		$resolver->method('unfilledMandatoryElements')->willReturn($unfilled);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($orgId);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return new MdtoPreconditions(new MdtoInputReaders($source, $bestand, $resolver), new \OCA\OpenRegister\Service\Configuration\AppSettingsAccess($config, $this->createMock(\OCP\App\IAppManager::class), $logger));
	}//end preconditions()

	/**
	 * An object.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function object(?string $uuid = 'u-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}//end object()

	/**
	 * Record the outcome of one call.
	 *
	 * @param string   $label The case.
	 * @param callable $call  The call.
	 *
	 * @return void
	 */
	private function outcome(string $label, callable $call): void {
		try {
			$this->calls[] = [$label, 'allowed', $call()];
		} catch (\InvalidArgumentException $e) {
			$this->calls[] = [$label, 'refused', $e->getMessage()];
		}
	}//end outcome()

	public function testTransferPreconditions(): void {
		$ok       = ['retentionPeriod' => 'P20Y', 'appraisal' => 'blijvend_bewaren'];
		$transfer = fn (array $facts, array $unfilled = []): \Closure => fn () => $this->preconditions(facts: $facts, unfilled: $unfilled)
			->assertTransferPreconditions(self::object());

		$this->outcome('allowed', $transfer($ok));
		$this->outcome('no period', $transfer(['retentionPeriod' => null] + $ok, ['a']));
		$this->outcome('one unmapped', $transfer($ok, ['archiefvormer']));
		$this->outcome('two unmapped', $transfer($ok, ['archiefvormer', 'naam']));
		$this->outcome('empty period', $transfer(['retentionPeriod' => ''] + $ok));

		$this->assertMatchesGolden(name: 'transfer');
	}

	public function testGeneratorPreconditions(): void {
		$ok       = ['retentionPeriod' => 'P20Y', 'appraisal' => 'blijvend_bewaren'];
		$generate = fn (array $facts): \Closure => fn () => $this->preconditions(facts: $facts)->assertGeneratorPreconditions(self::object(), []);

		$this->outcome('allowed', $generate($ok));
		$this->outcome('no period is fine', $generate(['retentionPeriod' => null] + $ok));
		foreach (['P2W', 'P', 'PT', '20Y', 'P1Y2M3DT4H5M6.5S', '-P1D', 7] as $period) {
			$this->outcome('period '.json_encode($period), $generate(['retentionPeriod' => $period] + $ok));
		}

		foreach ([null, 'bogus', 'vernietigen', 'blijvend_bewaren'] as $appraisal) {
			$this->outcome('appraisal '.json_encode($appraisal), $generate(['appraisal' => $appraisal] + $ok));
		}

		$this->outcome('no uuid', fn () => $this->preconditions(facts: $ok)->assertGeneratorPreconditions(self::object(uuid: ''), []));
		$this->outcome('no name', fn () => $this->preconditions(facts: $ok, name: '')->assertGeneratorPreconditions(self::object(), []));
		$this->outcome('no org', fn () => $this->preconditions(facts: $ok, orgId: '')->assertGeneratorPreconditions(self::object(), []));
		$files = $this->preconditions(facts: $ok, missing: ['bestand[0].naam']);
		$this->outcome('file inputs', fn () => $files->assertGeneratorPreconditions(self::object(), [[]]));
		$worst = $this->preconditions(facts: ['retentionPeriod' => 'x', 'appraisal' => null], name: '', missing: ['f'], orgId: '');
		$this->outcome('everything', fn () => $worst->assertGeneratorPreconditions(self::object(uuid: null), []));

		$this->assertMatchesGolden(name: 'generator');
	}

	public function testRequiredElementGaps(): void {
		$ok           = ['retentionPeriod' => 'P20Y', 'appraisal' => 'blijvend_bewaren'];
		$restricted   = $this->preconditions(facts: $ok);
		$unrestricted = $this->preconditions(facts: $ok, restrict: null);

		$this->outcome('restricted', fn () => $restricted->collectMdtoRequiredElementGaps(self::object()));
		$this->outcome('unrestricted', fn () => $unrestricted->collectMdtoRequiredElementGaps(self::object()));
		$this->outcome('report restricted', fn () => $restricted->reportMdtoRequiredElementGaps(self::object()));
		$this->outcome('report unrestricted', fn () => $unrestricted->reportMdtoRequiredElementGaps(self::object()));

		$this->assertMatchesGolden(name: 'gaps');
	}

	/**
	 * Compare the recorded outcomes with the stored golden file.
	 *
	 * @param string $name The golden name.
	 *
	 * @return void
	 */
	private function assertMatchesGolden(string $name): void {
		$actual = json_encode($this->calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
		$path   = __DIR__.'/fixtures/mdto-preconditions-golden/'.$name.'.json';
		if (getenv('GOLDEN_WRITE') === '1') {
			@mkdir(dirname($path), 0777, true);
			file_put_contents($path, $actual);
		}

		$this->assertFileExists($path);
		$this->assertSame(file_get_contents($path), $actual);
	}//end assertMatchesGolden()
}//end class
