<?php

/**
 * Save-time checks of a schema's anonymisation profile: it may name only
 * declared properties, and it may sit in the `x-openregister-archival` block
 * or the `archive` block but not both (design D-6).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\ArchivalDeclarationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Listener\ArchivalDeclarationChecksListener;
use OCA\OpenRegister\Service\Archival\AnonymisationProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ArchivalDeclarationRefusals::checkAnonymisationProfile().
 */
#[CoversClass(ArchivalDeclarationRefusals::class)]
#[UsesClass(\OCA\OpenRegister\Service\Archival\AnonymisationPlanner::class)]
#[CoversClass(ArchivalDeclarationChecksListener::class)]
#[UsesClass(SchemaDeclarationChecksEvent::class)]
#[UsesClass(\OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck::class)]
#[UsesClass(DiscardedAnnotations::class)]
class AnonymisationProfileSaveTest extends TestCase {

	/**
	 * A schema declaring `naam` and `bsn`, with the given archival and archive blocks.
	 *
	 * @param array<string, mixed>|null $archivalAnonymisation The `x-openregister-archival.anonymisation` profile.
	 * @param array<string, mixed>|null $archiveAnonymisation  The `archive.anonymisation` profile.
	 *
	 * @return Schema
	 */
	private function schema(?array $archivalAnonymisation, ?array $archiveAnonymisation): Schema {
		$schema = new Schema();
		$schema->setProperties(['naam' => ['type' => 'string'], 'bsn' => ['type' => 'string']]);

		if ($archivalAnonymisation !== null) {
			$schema->setConfiguration(['x-openregister-archival' => [AnonymisationProfile::ANNOTATION_KEY => $archivalAnonymisation]]);
		}

		if ($archiveAnonymisation !== null) {
			$schema->setArchive([AnonymisationProfile::ANNOTATION_KEY => $archiveAnonymisation]);
		}

		return $schema;
	}//end schema()

	/**
	 * The subject under test.
	 *
	 * @return ArchivalDeclarationRefusals
	 */
	private function refusals(): ArchivalDeclarationRefusals {
		return new ArchivalDeclarationRefusals(logger: new NullLogger());
	}//end refusals()

	/**
	 * A sound profile in either block, and no profile at all, pass.
	 *
	 * @return void
	 */
	public function testASoundProfileOrNoProfilePasses(): void {
		$sound = ['naam' => ['treatment' => AnonymisationProfile::REMOVE]];

		$this->refusals()->checkAnonymisationProfile(schema: $this->schema($sound, null));
		$this->refusals()->checkAnonymisationProfile(schema: $this->schema(null, $sound));
		$this->refusals()->checkAnonymisationProfile(schema: $this->schema(null, null));

		$this->addToAssertionCount(3);
	}//end testASoundProfileOrNoProfilePasses()

	/**
	 * A profile naming an undeclared property is refused, from either block.
	 *
	 * @return void
	 */
	public function testAnUndeclaredPropertyIsRefused(): void {
		$bad = ['naamVanBetrokkene' => ['treatment' => AnonymisationProfile::REMOVE]];

		foreach ([[$bad, null], [null, $bad]] as [$archival, $archive]) {
			try {
				$this->refusals()->checkAnonymisationProfile(schema: $this->schema($archival, $archive));
				$this->fail('An undeclared property should refuse the save.');
			} catch (Exception $e) {
				$this->assertStringContainsString('naamVanBetrokkene', $e->getMessage());
			}
		}
	}//end testAnUndeclaredPropertyIsRefused()

	/**
	 * A profile declared in both blocks is refused, naming both places.
	 *
	 * @return void
	 */
	public function testAProfileDeclaredInBothPlacesIsRefused(): void {
		$profile = ['naam' => ['treatment' => AnonymisationProfile::REMOVE]];

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/both.*archival.*archive|archive.*archival/i');

		$this->refusals()->checkAnonymisationProfile(schema: $this->schema($profile, $profile));
	}//end testAProfileDeclaredInBothPlacesIsRefused()

	/**
	 * The schema save runs the check: the archival listener's ARCHIVAL slot refuses a profile naming an undeclared property.
	 *
	 * @return void
	 */
	public function testTheArchivalSlotOfTheSaveRunsTheCheck(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new ArchivalDeclarationChecksListener(logger: new NullLogger()))->handle(event: $event);

		$archival = array_values(
			array_filter(
				$event->checks(),
				static fn ($check): bool => $check->position() === SchemaDeclarationOrder::ARCHIVAL
			)
		);
		$this->assertCount(1, $archival);

		// The profile sits in the archive block, so the archival annotation check before it has nothing to refuse.
		$bad = ['naamVanBetrokkene' => ['treatment' => AnonymisationProfile::REMOVE]];
		$this->expectExceptionMessage('naamVanBetrokkene');
		$archival[0]->check($this->schema(null, $bad), new DiscardedAnnotations());
	}//end testTheArchivalSlotOfTheSaveRunsTheCheck()
}//end class
