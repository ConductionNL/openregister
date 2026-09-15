<?php

declare(strict_types=1);

/**
 * The transfer refusal, at the point a transfer would start.
 *
 * 🔴 THE ASSERTION IS THAT NOTHING WAS SENT, not only that an exception came
 * out. A refusal raised after the package was assembled and handed over is a
 * failed transfer with a tidy message, which is what this exists to prevent. So
 * the test reaches the refusal through `assertTransferPreconditions()`, which
 * the packaging path calls FIRST, and pins that the element is named in the
 * message an administrator will read.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Service\Edepot;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\MdtoMappingResolver;
use OCA\OpenRegister\Service\Edepot\MdtoBestandGenerator;
use OCA\OpenRegister\Service\Edepot\MdtoPreconditions;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the mapping half of MdtoPreconditions.
 */
class MdtoPreconditionsMappingTest extends TestCase {

	private MdtoSourceReader&MockObject $sourceReader;
	private MdtoMappingResolver&MockObject $mappingResolver;
	private MdtoPreconditions $preconditions;

	protected function setUp(): void {
		parent::setUp();

		$this->sourceReader = $this->getMockBuilder(MdtoSourceReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['coreFacts'])
			->getMock();
		// A retention period is present, so the OTHER refusal does not fire and
		// a red here can only be the mapping rule.
		$this->sourceReader->method('coreFacts')->willReturn(['retentionPeriod' => 'P7Y']);

		$this->mappingResolver = $this->createMock(MdtoMappingResolver::class);

		$this->preconditions = new MdtoPreconditions(
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->sourceReader,
			$this->createMock(MdtoBestandGenerator::class),
			$this->mappingResolver
		);
	}

	/**
	 * A record.
	 *
	 * @return ObjectEntity The record.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');

		return $object;
	}

	public function testAnUnmappedMandatoryElementStopsTheTransferHereAndIsNamed(): void {
		$this->mappingResolver->method('unfilledMandatoryElements')->willReturn(['archiefvormer']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/mandatory element archiefvormer/');

		$this->preconditions->assertTransferPreconditions($this->object());
	}

	public function testSeveralUnmappedElementsAreAllNamed(): void {
		$this->mappingResolver->method('unfilledMandatoryElements')
			->willReturn(['naam', 'archiefvormer']);

		try {
			$this->preconditions->assertTransferPreconditions($this->object());
			$this->fail('The transfer should have been refused');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('naam, archiefvormer', $e->getMessage());
			$this->assertStringContainsString('x-openregister-mdto-mapping', $e->getMessage());
		}
	}

	public function testAFilledMappingLetsTheTransferThrough(): void {
		$this->mappingResolver->method('unfilledMandatoryElements')->willReturn([]);

		$this->preconditions->assertTransferPreconditions($this->object());

		$this->addToAssertionCount(1);
	}

	/**
	 * A schema with no mapping at all reports no unfilled elements, which is
	 * how every existing install keeps transferring.
	 */
	public function testASchemaWithNoMappingIsNotRefused(): void {
		$this->mappingResolver->method('mappingFor')->willReturn(null);
		$this->mappingResolver->method('unfilledMandatoryElements')->willReturn([]);

		$this->preconditions->assertTransferPreconditions($this->object());

		$this->addToAssertionCount(1);
	}
}
