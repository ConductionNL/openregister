<?php

declare(strict_types=1);

/**
 * RedactionWriteService Unit Tests
 *
 * Verifies the field-level redaction write path records before/after + ground,
 * and is DISTINCT from erase pseudonymise — the DataSubjectRequestService erase
 * path is never invoked (the service does not even depend on it).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Redaction
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Redaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use PHPUnit\Framework\TestCase;

/**
 * Test class for RedactionWriteService.
 */
class RedactionWriteServiceTest extends TestCase {

	/**
	 * A redaction records field/before/after/ground and a redaction marker,
	 * distinguishable from an erase pseudonymise record.
	 *
	 * @return void
	 */
	public function testRedactionRecordsBeforeAfterAndGround(): void {
		$case = new ObjectEntity();
		$case->setUuid('00000000-0000-0000-0000-000000000000');
		$case->setObject(['notes' => 'sensitive third-party name', 'redactions' => []]);

		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);

		$savedData = null;
		$accessor->expects($this->once())
			->method('save')
			->willReturnCallback(
				function ($c, $data) use (&$savedData, $case) {
					$savedData = $data;
					return $case;
				}
			);

		$service = new RedactionWriteService($accessor);
		$result = $service->applyRedaction(
			caseUuid: '00000000-0000-0000-0000-000000000000',
			field: 'notes',
			after: '[redacted]',
			ground: 'third-party-data'
		);

		$this->assertSame('sensitive third-party name', $result['before']);
		$this->assertSame('[redacted]', $result['after']);
		$this->assertSame('third-party-data', $result['ground']);
		$this->assertSame(RedactionWriteService::RECORD_TYPE, $result['recordType']);

		// The case now carries the redaction entry and the redacted value.
		$this->assertSame('[redacted]', $savedData['notes']);
		$this->assertCount(1, $savedData['redactions']);
		$entry = $savedData['redactions'][0];
		$this->assertSame('notes', $entry['field']);
		$this->assertSame('sensitive third-party name', $entry['before']);
		$this->assertSame('third-party-data', $entry['ground']);
		$this->assertSame('redaction', $entry['recordType']);

	}//end testRedactionRecordsBeforeAfterAndGround()

	/**
	 * The redaction service does not depend on DataSubjectRequestService — it
	 * cannot invoke the erase path (constructor takes only the accessor). This
	 * statically enforces "distinct from erase".
	 *
	 * @return void
	 */
	public function testServiceDoesNotDependOnEraseService(): void {
		$ctor = (new \ReflectionClass(RedactionWriteService::class))->getConstructor();
		$params = $ctor->getParameters();

		$types = array_map(
			static function (\ReflectionParameter $p): string {
				$t = $p->getType();
				return ($t instanceof \ReflectionNamedType) ? $t->getName() : '';
			},
			$params
		);

		$this->assertNotContains(
			'OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService',
			$types,
			'RedactionWriteService MUST NOT depend on the erase service (redaction is distinct from erase pseudonymise).'
		);

	}//end testServiceDoesNotDependOnEraseService()
}//end class
