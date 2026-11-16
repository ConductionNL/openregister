<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ClassificationCaller;
use OCA\OpenRegister\Service\Rbac\ClassificationListTerm;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use OCA\OpenRegister\Service\Rbac\ClearanceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The list path admits only the labels a caller may see, as an `IN` block.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ClassificationListTerm
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationCaller
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationLimits
 * @uses   \OCA\OpenRegister\Service\Rbac\ClearanceResolver
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
 */
class ClassificationListTermTest extends TestCase {

	private const LEVELS = ['openbaar', 'intern', 'vertrouwelijk', 'geheim'];

	/**
	 * A schema with the four-level block, or without one.
	 *
	 * @param bool $classified Whether the schema declares the block.
	 *
	 * @return Schema
	 */
	private static function schema(bool $classified = true): Schema {
		$schema = new Schema();
		$schema->setSlug('document');
		$schema->setProperties(['vertrouwelijkheidaanduiding' => ['type' => 'string']]);
		if ($classified === true) {
			$schema->setConfiguration(
				[
					ClassificationScale::ANNOTATION => [
						'property' => 'vertrouwelijkheidaanduiding',
						'levels' => self::LEVELS,
						'clearances' => [['group' => 'juristen', 'level' => 'geheim'], ['group' => 'medewerkers', 'level' => 'intern']],
						'default' => 'openbaar',
						'ceilings' => [['scope' => 'public', 'atOrAbove' => 'intern']],
					],
				]
			);
		}

		return $schema;
	}

	/**
	 * The block for one caller.
	 *
	 * @param string|null        $userId The caller.
	 * @param array<int, string> $groups Their groups.
	 * @param string             $action The action.
	 * @param bool               $classified Whether the schema declares the block.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function blockFor(?string $userId, array $groups, string $action = 'read', bool $classified = true): ?array {
		return (new ClassificationListTerm(clearances: new ClearanceResolver(logger: new NullLogger())))->matchBlock(
			schema: self::schema(classified: $classified),
			caller: new ClassificationCaller(userId: $userId, groups: $groups, action: $action)
		);
	}

	/**
	 * A schema without the block narrows nothing.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheBlockNarrowsNothing(): void {
		$this->assertNull(self::blockFor(userId: 'ann', groups: ['medewerkers'], classified: false));
	}

	/**
	 * A caller cleared to `intern` lists only the labels at or below it.
	 *
	 * @return void
	 */
	public function testAReadAdmitsTheLabelsAtOrBelowTheClearance(): void {
		$this->assertSame(
			['vertrouwelijkheidaanduiding' => ['$in' => ['openbaar', 'intern']]],
			self::blockFor(userId: 'ann', groups: ['medewerkers'])
		);
	}

	/**
	 * An unmapped caller takes the default, the lowest level here.
	 *
	 * @return void
	 */
	public function testAnUnmappedCallerListsAtTheDefault(): void {
		$this->assertSame(
			['vertrouwelijkheidaanduiding' => ['$in' => ['openbaar']]],
			self::blockFor(userId: 'bob', groups: ['elsewhere'])
		);
	}

	/**
	 * A caller cleared to the top needs no term: every row, unlabelled ones included, is theirs to see.
	 *
	 * @return void
	 */
	public function testACallerClearedToTheTopIsNotNarrowed(): void {
		$this->assertNull(self::blockFor(userId: 'cas', groups: ['medewerkers', 'juristen']));
	}

	/**
	 * A clearance does not narrow a write list; only a ceiling would, and it holds for anonymous callers only.
	 *
	 * @return void
	 */
	public function testANonReadActionOfASignedInCallerIsNotNarrowed(): void {
		$this->assertNull(self::blockFor(userId: 'bob', groups: [], action: 'update'));
	}

	/**
	 * An anonymous caller loses the labels at or above the ceiling on `public`, on every action.
	 *
	 * @return void
	 */
	public function testACeilingRemovesItsLabelsFromAnAnonymousList(): void {
		$this->assertSame(['vertrouwelijkheidaanduiding' => ['$in' => ['openbaar']]], self::blockFor(userId: null, groups: []));
		$this->assertSame(
			['vertrouwelijkheidaanduiding' => ['$in' => ['openbaar']]],
			self::blockFor(userId: null, groups: [], action: 'update'),
			'a ceiling refuses its scope on every action, not only a read'
		);
	}

	/**
	 * A ceiling at the lowest level leaves an anonymous caller no label at all: an empty list, never null.
	 *
	 * @return void
	 */
	public function testACeilingAtTheBottomAdmitsNoLabel(): void {
		$schema = self::schema();
		$config = $schema->getConfiguration();
		$config[ClassificationScale::ANNOTATION]['ceilings'] = [['scope' => 'public', 'atOrAbove' => 'openbaar']];
		$schema->setConfiguration($config);

		$block = (new ClassificationListTerm(clearances: new ClearanceResolver(logger: new NullLogger())))->matchBlock(
			schema: $schema,
			caller: new ClassificationCaller(userId: null, groups: [], action: 'read')
		);

		$this->assertSame(['vertrouwelijkheidaanduiding' => ['$in' => []]], $block);
	}
}
