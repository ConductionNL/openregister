<?php

/**
 * Unit tests for TimelineKindService — what a declared kind lets an entry carry.
 *
 * Covers the declaration, the field validation an entry passes through, the
 * undeclared kind that is refused rather than silently downgraded, and the
 * plain note that carries nothing and is meant to.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\TimelineKind;
use OCA\OpenRegister\Db\TimelineKindMapper;
use OCA\OpenRegister\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TimelineKindServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimelineKindServiceTest extends TestCase {
	/**
	 * Kind mapper mock.
	 *
	 * @var TimelineKindMapper&MockObject
	 */
	private TimelineKindMapper&MockObject $kindMapper;

	/**
	 * Service under test.
	 *
	 * @var TimelineKindService
	 */
	private TimelineKindService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->kindMapper = $this->createMock(TimelineKindMapper::class);
		$this->service = new TimelineKindService($this->kindMapper);
	}

	private function contactmoment(bool $followUp = false): TimelineKind {
		$kind = new TimelineKind();
		$kind->setSlug('contactmoment');
		$kind->setProperties(
			[
				'channel' => ['type' => 'string', 'enum' => ['telefoon', 'balie', 'email']],
				'direction' => ['type' => 'string', 'enum' => ['inkomend', 'uitgaand']],
				'duration' => ['type' => 'integer'],
			]
		);
		$kind->setRequired(['channel', 'direction']);
		$kind->setFollowUp($followUp);

		return $kind;
	}

	public function testAContactMomentCarriesAChannelAndADirection(): void {
		$this->kindMapper->method('findBySlug')->willReturn($this->contactmoment());

		$accepted = $this->service->validateFields(
			'contactmoment',
			['channel' => 'telefoon', 'direction' => 'inkomend']
		);

		$this->assertSame(['channel' => 'telefoon', 'direction' => 'inkomend'], $accepted);
	}

	public function testAValueOutsideTheEnumIsRefused(): void {
		$this->kindMapper->method('findBySlug')->willReturn($this->contactmoment());

		try {
			$this->service->validateFields('contactmoment', ['channel' => 'duif', 'direction' => 'inkomend']);
			$this->fail('A channel the kind does not declare should be refused');
		} catch (TimelineValidationException $e) {
			$this->assertArrayHasKey('channel', $e->getErrors());
		}
	}

	public function testAValueOfTheWrongTypeIsRefused(): void {
		$this->kindMapper->method('findBySlug')->willReturn($this->contactmoment());

		try {
			$this->service->validateFields(
				'contactmoment',
				['channel' => 'balie', 'direction' => 'inkomend', 'duration' => 'ten minutes']
			);
			$this->fail('A string where the kind declares an integer should be refused');
		} catch (TimelineValidationException $e) {
			$this->assertArrayHasKey('duration', $e->getErrors());
		}
	}

	public function testAMissingRequiredFieldIsRefusedAndEveryOneIsNamed(): void {
		$this->kindMapper->method('findBySlug')->willReturn($this->contactmoment());

		try {
			$this->service->validateFields('contactmoment', []);
			$this->fail('Both required fields are missing');
		} catch (TimelineValidationException $e) {
			// Named together, so a form is fixed in one round trip rather than four.
			$this->assertSame(['channel', 'direction'], array_keys($e->getErrors()));
		}
	}

	public function testAFieldTheKindDoesNotDeclareIsDropped(): void {
		$this->kindMapper->method('findBySlug')->willReturn($this->contactmoment());

		$accepted = $this->service->validateFields(
			'contactmoment',
			['channel' => 'balie', 'direction' => 'uitgaand', 'smuggled' => 'anything']
		);

		$this->assertArrayNotHasKey('smuggled', $accepted);
	}

	public function testAnUndeclaredKindIsRefusedRatherThanSilentlyAPlainNote(): void {
		$this->kindMapper->method('findBySlug')->willReturn(null);

		$this->expectException(TimelineValidationException::class);
		$this->service->validateFields('contactmoment', ['channel' => 'balie']);
	}

	public function testAnEntryWithNoKindCarriesNoFields(): void {
		$this->kindMapper->expects($this->never())->method('findBySlug');

		$this->assertSame([], $this->service->validateFields(null, ['channel' => 'balie']));
		$this->assertSame([], $this->service->validateFields('   ', ['channel' => 'balie']));
	}

	public function testOnlyAKindThatDeclaresOneCarriesAFollowUp(): void {
		$this->kindMapper->method('findBySlug')->willReturn($this->contactmoment(true));

		$this->assertTrue($this->service->carriesFollowUp('contactmoment'));
		$this->assertFalse($this->service->carriesFollowUp(null));
	}

	public function testAKindWithoutASlugIsRefused(): void {
		$this->expectException(TimelineValidationException::class);
		$this->service->declareKind(['title' => 'Contact moment']);
	}

	public function testDeclaringAKindThatDoesNotExistInsertsIt(): void {
		$this->kindMapper->method('findBySlug')->willReturn(null);
		$this->kindMapper->expects($this->once())->method('insert')
			->willReturnCallback(static fn (TimelineKind $kind): TimelineKind => $kind);
		$this->kindMapper->expects($this->never())->method('update');

		$kind = $this->service->declareKind(
			[
				'slug' => 'Contactmoment',
				'properties' => ['channel' => ['type' => 'string']],
				'required' => ['channel'],
				'followUp' => true,
			]
		);

		$this->assertSame('contactmoment', $kind->getSlug());
		$this->assertTrue($kind->getFollowUp());
		$this->assertNotNull($kind->getUuid());
	}

	public function testDeclaringAKindThatExistsRewritesIt(): void {
		$this->kindMapper->method('findBySlug')->willReturn($this->contactmoment());
		$this->kindMapper->expects($this->never())->method('insert');
		$this->kindMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (TimelineKind $kind): TimelineKind => $kind);

		$kind = $this->service->declareKind(['slug' => 'contactmoment', 'title' => 'Contact moment']);

		$this->assertSame('Contact moment', $kind->getTitle());
	}

	public function testWithdrawingAKindNobodyDeclaredRemovesNothing(): void {
		$this->kindMapper->method('findBySlug')->willReturn(null);
		$this->kindMapper->expects($this->never())->method('delete');

		$this->assertFalse($this->service->withdraw('contactmoment'));
	}
}
