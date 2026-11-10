<?php

/**
 * RegisterSlugResolver resolves a register by whichever slug it carries here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\RegisterSlugResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every case here is an instance state that exists in the field right now:
 * migrated, unmigrated, both, neither. The repair steps are per-instance, so
 * which one a given deployment is in depends on when it last ran repair.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
class RegisterSlugResolverTest extends TestCase {

	/**
	 * The register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An instance carrying only the given slugs.
	 *
	 * Mirrors RegisterMapper::findIdsBySlugs(), which answers with EVERY probed
	 * slug as a key and an empty id list for the ones that do not exist. A
	 * double that omitted the misses would be easier to write and would hide
	 * the exact distinction the resolver turns on.
	 *
	 * @param list<string> $present Slugs this instance's register table carries.
	 *
	 * @return RegisterSlugResolver The resolver under test.
	 */
	private function instanceCarrying(array $present): RegisterSlugResolver {
		$this->registerMapper->method('findIdsBySlugs')->willReturnCallback(
			/**
			 * @param array<int,string> $slugs Probed slugs.
			 *
			 * @return array<string,array<int,string>>
			 */
			static function (array $slugs) use ($present): array {
				$map = [];
				foreach ($slugs as $index => $slug) {
					$lowered = strtolower($slug);
					$map[$lowered] = [];
					if (in_array($lowered, $present, true) === true) {
						$map[$lowered] = [(string)(10 + $index)];
					}
				}

				return $map;
			}
		);

		return new RegisterSlugResolver(registerMapper: $this->registerMapper, logger: $this->logger);
	}//end instanceCarrying()

	/**
	 * A migrated instance resolves to the new slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAMigratedInstanceResolvesToTheNewSlug(): void {
		$resolution = $this->instanceCarrying(['buildiq'])->resolve(canonical: 'buildiq');

		$this->assertSame('buildiq', $resolution->slug);
		$this->assertSame(RegisterSlugResolution::RESOLVED, $resolution->state);
		$this->assertTrue($resolution->isResolved());
	}//end testAMigratedInstanceResolvesToTheNewSlug()

	/**
	 * An unmigrated instance resolves to the old slug.
	 *
	 * This is the half of the estate a literal swap to the new slug would have
	 * broken, and it breaks the same silent way round: the read simply returns
	 * nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAnUnmigratedInstanceResolvesToTheOldSlug(): void {
		$resolution = $this->instanceCarrying(['openbuild'])->resolve(canonical: 'buildiq');

		$this->assertSame('openbuild', $resolution->slug);
		$this->assertSame(RegisterSlugResolution::RESOLVED, $resolution->state);
		$this->assertNotSame($resolution->canonical, $resolution->slug, 'This instance has not run the repair step.');
	}//end testAnUnmigratedInstanceResolvesToTheOldSlug()

	/**
	 * A consumer pinned to the OLD slug on a MIGRATED instance reads nothing.
	 *
	 * The defect, stated as an assertion. `openbuild` is not a slug this
	 * instance carries, so a caller holding that literal gets no register and
	 * therefore no rows, with no exception anywhere to notice. The resolver is
	 * the only thing on this path that can tell the two apart, and it does:
	 * probing the pinned literal ALONE reports absent, while probing the
	 * canonical slug resolves.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAPinnedOldSlugOnAMigratedInstanceIsDetectedAsAbsent(): void {
		$resolver = $this->instanceCarrying(['buildiq']);

		$pinned = $resolver->resolve(canonical: 'openbuild', candidates: ['openbuild']);
		$this->assertNull($pinned->slug, 'The pinned literal must not resolve on a migrated instance.');
		$this->assertTrue($pinned->isAbsent());
		$this->assertSame([], $pinned->matched);

		$resolved = $resolver->resolve(canonical: 'buildiq');
		$this->assertSame('buildiq', $resolved->slug, 'The same instance answers under the canonical slug.');
	}//end testAPinnedOldSlugOnAMigratedInstanceIsDetectedAsAbsent()

	/**
	 * An absent register yields no slug, never the canonical one.
	 *
	 * Returning the canonical slug here would be the whole defect reinstated
	 * inside the fix: the caller would read, get zero rows, and log "no data".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAnAbsentRegisterYieldsNoSlug(): void {
		$resolution = $this->instanceCarrying([])->resolve(canonical: 'buildiq');

		$this->assertNull($resolution->slug);
		$this->assertNotSame('buildiq', $resolution->slug);
		$this->assertTrue($resolution->isAbsent());
		$this->assertFalse($resolution->isResolved());
	}//end testAnAbsentRegisterYieldsNoSlug()

	/**
	 * Both slugs present is reported as ambiguous and reads stay on the new row.
	 *
	 * Reachable because the repair step refuses to merge when both rows exist,
	 * rather than guessing which one holds the objects.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testBothSlugsPresentIsAmbiguousAndPrefersTheCanonical(): void {
		$this->logger->expects($this->once())->method('warning');

		$resolution = $this->instanceCarrying(['buildiq', 'openbuild'])->resolve(canonical: 'buildiq');

		$this->assertSame('buildiq', $resolution->slug);
		$this->assertTrue($resolution->isAmbiguous());
		$this->assertSame(['buildiq', 'openbuild'], $resolution->matched);
	}//end testBothSlugsPresentIsAmbiguousAndPrefersTheCanonical()

	/**
	 * A slug that is not the old app id still resolves.
	 *
	 * stackiq renamed the register `voorzieningen`. Its former app id
	 * `softwarecatalog` was never a register slug on any instance, so a probe
	 * derived from the app rename map matches nothing and calls the register
	 * absent exactly where it is present.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testTheCandidatesAreDeclaredNotDerivedFromAppIds(): void {
		$resolution = $this->instanceCarrying(['voorzieningen'])->resolve(canonical: 'stackiq');

		$this->assertSame('voorzieningen', $resolution->slug);
		$this->assertContains('voorzieningen', $resolution->candidates);
		$this->assertNotContains(
			'softwarecatalog',
			$resolution->candidates,
			'The former APP id was never a register slug; probing it would find nothing.'
		);
	}//end testTheCandidatesAreDeclaredNotDerivedFromAppIds()

	/**
	 * A slug with no recorded rename resolves to itself.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testARegisterWithNoRenameResolvesToItself(): void {
		$resolution = $this->instanceCarrying(['publication'])->resolve(canonical: 'publication');

		$this->assertSame('publication', $resolution->slug);
		$this->assertSame(['publication'], $resolution->candidates);
	}//end testARegisterWithNoRenameResolvesToItself()

	/**
	 * A failed read is an absence, not a guess.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAFailedReadReportsAbsenceAndLogsAnError(): void {
		$this->registerMapper->method('findIdsBySlugs')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error');

		$resolver = new RegisterSlugResolver(registerMapper: $this->registerMapper, logger: $this->logger);
		$resolution = $resolver->resolve(canonical: 'buildiq');

		$this->assertNull($resolution->slug);
		$this->assertTrue($resolution->isAbsent());
	}//end testAFailedReadReportsAbsenceAndLogsAnError()

	/**
	 * The probe is memoised for the life of the request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testTheProbeIsMemoisedPerCandidateList(): void {
		$this->registerMapper->expects($this->once())
			->method('findIdsBySlugs')
			->willReturn(['buildiq' => ['10'], 'openbuild' => []]);

		$resolver = new RegisterSlugResolver(registerMapper: $this->registerMapper, logger: $this->logger);

		$this->assertSame('buildiq', $resolver->slugOrNull(canonical: 'buildiq'));
		$this->assertSame('buildiq', $resolver->slugOrNull(canonical: 'buildiq'));
		$this->assertSame('buildiq', $resolver->resolve(canonical: 'BuildIQ')->slug);
	}//end testTheProbeIsMemoisedPerCandidateList()

	/**
	 * An empty canonical slug is refused rather than probed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAnEmptyCanonicalSlugResolvesToNothing(): void {
		$this->registerMapper->expects($this->never())->method('findIdsBySlugs');

		$resolver = new RegisterSlugResolver(registerMapper: $this->registerMapper, logger: $this->logger);
		$resolution = $resolver->resolve(canonical: '  ');

		$this->assertNull($resolution->slug);
		$this->assertTrue($resolution->isAbsent());
	}//end testAnEmptyCanonicalSlugResolvesToNothing()
}//end class
