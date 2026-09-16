<?php

/**
 * Which rule decided this, and where it was written.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ProvenanceResolver;
use PHPUnit\Framework\TestCase;

/**
 * A grant says where it came from, and an absence says which rule removed it.
 */
class ProvenanceResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var ProvenanceResolver
	 */
	private ProvenanceResolver $resolver;

	/**
	 * Build the resolver before each case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ProvenanceResolver(new DenyResolver());
	}//end setUp()

	/**
	 * The principals of one caller.
	 *
	 * @param string   $userId The caller.
	 * @param string[] $groups Their groups.
	 *
	 * @return array<int, string> The principal names.
	 */
	private function principals(string $userId, array $groups): array {
		return (new DenyResolver())->principalsFor(userId: $userId, userGroups: $groups);
	}//end principals()

	/**
	 * A grant written on the schema names the schema.
	 *
	 * @return void
	 */
	public function testASchemaGrantNamesTheSchema(): void {
		$record = $this->resolver->forAction(
			action: 'read',
			principals: $this->principals('ana', ['behandelaars']),
			schemaAuthorization: ['read' => ['behandelaars']]
		);

		$this->assertTrue($record['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_SCHEMA, $record['source']);
		$this->assertSame('behandelaars', $record['principal']);
	}//end testASchemaGrantNamesTheSchema()

	/**
	 * 🔴 The most specific level that decided is the one reported.
	 *
	 * An answer naming a broader rule than the one that actually decided sends
	 * an administrator to the wrong screen, edits the wrong rule, and leaves the
	 * behaviour exactly where it was.
	 *
	 * @return void
	 */
	public function testTheObjectBeatsTheSchemaWhichBeatsTheRegister(): void {
		$principals = $this->principals('ana', ['behandelaars']);
		$register = ['read' => ['behandelaars']];
		$schema = ['read' => ['behandelaars']];
		$object = ['read' => ['behandelaars']];

		$this->assertSame(
			ProvenanceResolver::SOURCE_OBJECT,
			$this->resolver->forAction(
				action: 'read',
				principals: $principals,
				objectAuthorization: $object,
				schemaAuthorization: $schema,
				registerAuth: $register
			)['source']
		);

		$this->assertSame(
			ProvenanceResolver::SOURCE_SCHEMA,
			$this->resolver->forAction(
				action: 'read',
				principals: $principals,
				schemaAuthorization: $schema,
				registerAuth: $register
			)['source']
		);

		$this->assertSame(
			ProvenanceResolver::SOURCE_REGISTER,
			$this->resolver->forAction(
				action: 'read',
				principals: $principals,
				registerAuth: $register
			)['source']
		);
	}//end testTheObjectBeatsTheSchemaWhichBeatsTheRegister()

	/**
	 * A grant through a role names the ROLE, not the group behind it.
	 *
	 * The role is what an administrator edits. Reporting the group would be true
	 * and useless: nobody granted that group the verb, the role did.
	 *
	 * @return void
	 */
	public function testARoleGrantNamesTheRole(): void {
		$record = $this->resolver->forAction(
			action: 'update',
			principals: $this->principals('ana', ['behandelaars']),
			schemaAuthorization: ['roles' => ['behandelaar' => ['behandelaars']]],
			roleDefinitions: [['name' => 'behandelaar', 'actions' => ['read', 'update']]]
		);

		$this->assertTrue($record['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_ROLE, $record['source']);
		$this->assertSame('behandelaar', $record['role']);
		$this->assertSame('behandelaars', $record['principal']);
	}//end testARoleGrantNamesTheRole()

	/**
	 * A role that does not carry the verb does not grant it.
	 *
	 * @return void
	 */
	public function testARoleWithoutTheVerbGrantsNothing(): void {
		$record = $this->resolver->forAction(
			action: 'delete',
			principals: $this->principals('ana', ['behandelaars']),
			schemaAuthorization: ['roles' => ['behandelaar' => ['behandelaars']]],
			roleDefinitions: [['name' => 'behandelaar', 'actions' => ['read', 'update']]]
		);

		$this->assertFalse($record['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_NONE, $record['source']);
	}//end testARoleWithoutTheVerbGrantsNothing()

	/**
	 * 🔴 An absence a deny caused says so, and names the deny.
	 *
	 * The requirement this whole class exists for. An absence with no reason is
	 * the worst answer this layer can give: the case worker sees nothing, the
	 * administrator sees rules that look correct, and only a hand walk of the
	 * cascade connects them.
	 *
	 * @return void
	 */
	public function testAnAbsenceNamesTheDenyThatRemovedIt(): void {
		$denial = ['rule' => 'waarnemers', 'principal' => 'waarnemers', 'action' => 'update', 'conditional' => false];

		$record = $this->resolver->forAction(
			action: 'update',
			principals: $this->principals('ana', ['behandelaars', 'waarnemers']),
			schemaAuthorization: ['update' => ['behandelaars']],
			denial: $denial,
			denyEnforced: true
		);

		$this->assertFalse($record['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_DENY, $record['source']);
		$this->assertSame('waarnemers', $record['principal']);
		$this->assertSame($denial, $record['deny']);
		$this->assertSame(
			ProvenanceResolver::SOURCE_SCHEMA,
			$record['wouldHaveBeenGrantedBy'],
			'The rule the deny beat is what tells an administrator which two rules are in tension.'
		);
	}//end testAnAbsenceNamesTheDenyThatRemovedIt()

	/**
	 * 🔴 A staged deny rides beside the grant that still stands.
	 *
	 * This is the dry run made readable (D15). The verb is there today, and this
	 * is the rule that takes it away when the switch moves. The same fixture as
	 * the case above, differing only in the mode, so the two together prove the
	 * field is doing the work and not the fixture.
	 *
	 * @return void
	 */
	public function testAStagedDenyIsReportedBesideTheGrantItHasNotTakenYet(): void {
		$denial = ['rule' => 'waarnemers', 'principal' => 'waarnemers', 'action' => 'update', 'conditional' => false];

		$record = $this->resolver->forAction(
			action: 'update',
			principals: $this->principals('ana', ['behandelaars', 'waarnemers']),
			schemaAuthorization: ['update' => ['behandelaars']],
			denial: $denial,
			denyEnforced: false
		);

		$this->assertTrue($record['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_SCHEMA, $record['source']);
		$this->assertNull($record['deny']);
		$this->assertSame($denial, $record['stagedDeny']);
	}//end testAStagedDenyIsReportedBesideTheGrantItHasNotTakenYet()

	/**
	 * A schema nobody configured is reported as such, not as a refusal.
	 *
	 * "Nobody granted you this" and "nothing is configured here" are two
	 * different screens for an administrator, and collapsing them costs a
	 * morning.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCascadeReadsAsDefaultOpen(): void {
		$record = $this->resolver->forAction(
			action: 'read',
			principals: $this->principals('ana', ['behandelaars'])
		);

		$this->assertTrue($record['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_DEFAULT_OPEN, $record['source']);
		$this->assertNull($record['rule']);
	}//end testAnUnconfiguredCascadeReadsAsDefaultOpen()

	/**
	 * A configured cascade that names nobody is a refusal with a reason.
	 *
	 * @return void
	 */
	public function testAConfiguredCascadeThatNamesNobodyReadsAsNone(): void {
		$record = $this->resolver->forAction(
			action: 'read',
			principals: $this->principals('ana', ['behandelaars']),
			schemaAuthorization: ['read' => ['anderen']]
		);

		$this->assertFalse($record['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_NONE, $record['source']);
	}//end testAConfiguredCascadeThatNamesNobodyReadsAsNone()

	/**
	 * A user named directly is reported as that user, not as a group.
	 *
	 * @return void
	 */
	public function testAUserLevelGrantNamesTheUser(): void {
		$record = $this->resolver->forAction(
			action: 'read',
			principals: $this->principals('ana', []),
			schemaAuthorization: ['read' => [['user' => 'ana']]]
		);

		$this->assertTrue($record['granted']);
		$this->assertSame('user:ana', $record['principal']);
	}//end testAUserLevelGrantNamesTheUser()

	/**
	 * Every action in a vocabulary is reported in one call.
	 *
	 * @return void
	 */
	public function testAWholeVocabularyIsReportedAtOnce(): void {
		$provenance = $this->resolver->forActions(
			actions: ['read', 'update', 'delete'],
			principals: $this->principals('ana', ['behandelaars']),
			blocks: [
				'schema' => ['read' => ['behandelaars'], 'update' => ['behandelaars']],
			],
			denials: [
				'update' => ['rule' => 'behandelaars', 'principal' => 'behandelaars', 'action' => 'update', 'conditional' => false],
			],
			enforced: true
		);

		$this->assertSame(['read', 'update', 'delete'], array_keys($provenance));
		$this->assertTrue($provenance['read']['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_DENY, $provenance['update']['source']);
		$this->assertFalse($provenance['delete']['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_NONE, $provenance['delete']['source']);
	}//end testAWholeVocabularyIsReportedAtOnce()

	/**
	 * The already-resolved `name => actions` map is read as well as the stored
	 * list of definitions.
	 *
	 * A caller holding the resolved map is the caller whose answer is most
	 * accurate, because that map has the role hierarchy already folded in.
	 *
	 * @return void
	 */
	public function testAFlatRoleMapIsReadToo(): void {
		$record = $this->resolver->forAction(
			action: 'update',
			principals: $this->principals('ana', ['senioren']),
			schemaAuthorization: ['roles' => ['senior' => ['senioren']]],
			roleDefinitions: ['senior' => ['read', 'update', 'delete']]
		);

		$this->assertTrue($record['granted']);
		$this->assertSame('senior', $record['role']);
	}//end testAFlatRoleMapIsReadToo()
}//end class
