<?php

/**
 * ReconcileOrganisationCommand: reports or repairs objects whose organisation property and rights disagree.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\Organisation\OrganisationAnnotation;
use OCA\OpenRegister\Service\Organisation\OrganisationFromProperty;
use OCA\OpenRegister\Service\WriteCause;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ openregister:organisation:reconcile --register <r> --schema <s> [--apply]`.
 *
 * A schema that declares `x-openregister-organisation` ties an object's rights
 * to the unit its property names, but objects stored before the declaration
 * carry whatever organisation the save stamped at the time. This lists those
 * disagreements and, with `--apply`, sets `@self.organisation` from the
 * property and writes one audit row per changed object.
 *
 * A dry run unless `--apply` is given, because the write changes who may read
 * those objects: an administrator chooses to run it.
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
 */
class ReconcileOrganisationCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface    $catalog The registers, schemas and object tables.
	 * @param AuditTrailMapperInterface $audit   Writes the audit row per changed object.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly AuditTrailMapperInterface $audit,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:organisation:reconcile')
			->setDescription(
				'List objects whose organisation property and @self.organisation disagree, and with --apply set the organisation from the property.'
			)
			->addOption(name: 'register', mode: InputOption::VALUE_REQUIRED, description: 'Register slug, uuid or id')
			->addOption(name: 'schema', mode: InputOption::VALUE_REQUIRED, description: 'Schema slug, uuid or id')
			->addOption(name: 'apply', mode: InputOption::VALUE_NONE, description: 'Write the organisations; without it the run is a dry run');
	}//end configure()

	/**
	 * List, and with --apply repair, the disagreements of one register and schema.
	 *
	 * @param InputInterface  $input  The options.
	 * @param OutputInterface $output Where the report goes.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = ($input->getOption('apply') === true);
		$registerId = $input->getOption('register');
		$schemaId = $input->getOption('schema');
		if (is_string($registerId) === false || is_string($schemaId) === false) {
			$output->writeln('<error>--register and --schema are both required.</error>');
			return Command::FAILURE;
		}

		try {
			$register = $this->catalog->registers()->find($registerId, _multitenancy: false);
			$schema = $this->catalog->schemas()->find($schemaId, _multitenancy: false);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$property = OrganisationAnnotation::propertyOf(schema: $schema);
		if ($property === null) {
			$output->writeln(
				'<comment>This schema declares no ' . OrganisationAnnotation::ANNOTATION . ' — nothing to reconcile.</comment>'
			);
			return Command::SUCCESS;
		}

		return $this->reconcile(
			table: new RegisterSchema(register: $register, schema: $schema),
			property: $property,
			apply: $apply,
			output: $output
		);
	}//end execute()

	/**
	 * Walk one register and schema, reporting and optionally repairing.
	 *
	 * @param RegisterSchema  $table    The register and schema.
	 * @param string          $property The property the schema names.
	 * @param bool            $apply    Whether to write.
	 * @param OutputInterface $output   Where the report goes.
	 *
	 * @return int The exit code.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The dry-run flag mirrors the other repair commands.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
	 */
	private function reconcile(RegisterSchema $table, string $property, bool $apply, OutputInterface $output): int {
		$objects = $this->catalog->tables()->findAllInRegisterSchemaTable(
			table: $table,
			listing: new ListQuery(limit: ListQuery::UNBOUNDED)
		);

		$disagreeing = 0;
		$changed = 0;
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$named = OrganisationFromProperty::disagreement(object: $object, property: $property);
			if ($named === null) {
				continue;
			}

			$disagreeing += 1;
			$output->writeln(
				sprintf(
					'  %s: %s=%s @self.organisation=%s',
					(string)$object->getUuid(),
					$property,
					$named,
					self::scopeOf(object: $object)
				)
			);
			if ($apply === true && $this->moveObject(object: $object, table: $table, organisation: $named) === true) {
				$changed += 1;
			}
		}

		$note = ' (dry run, nothing written; add --apply to write)';
		if ($apply === true) {
			$note = '';
		}

		$output->writeln(
			sprintf('<info>Disagreeing %d, changed %d%s</info>', $disagreeing, $changed, $note)
		);

		return Command::SUCCESS;
	}//end reconcile()

	/**
	 * The organisation an object's rights follow today, for the report.
	 *
	 * @param ObjectEntity $object The stored object.
	 *
	 * @return string The organisation, or "none".
	 */
	private static function scopeOf(ObjectEntity $object): string {
		$stored = (string)$object->getOrganisation();
		if ($stored === '') {
			return 'none';
		}

		return $stored;
	}//end scopeOf()

	/**
	 * Give one object the organisation its property names, and record it.
	 *
	 * The audit row's cause is `migration`: the write is a repair, and a row
	 * reading as a person's edit would put this run's changes in with the
	 * changes of whoever last touched the object.
	 *
	 * @param ObjectEntity  $object       The stored object.
	 * @param RegisterSchema $table       The register and schema it belongs to.
	 * @param string        $organisation The organisation its property names.
	 *
	 * @return bool True when the object was written.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::runAs() is the ambient audit-cause frame; there is no instance to inject.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-existing-disagreements-are-reported-and-reconciled-req-oop-002
	 */
	private function moveObject(ObjectEntity $object, RegisterSchema $table, string $organisation): bool {
		$before = clone $object;
		$object->setOrganisation($organisation);

		return (bool)WriteCause::runAs(
			frame: new CauseFrame(cause: WriteCause::MIGRATION),
			operation: function () use ($object, $before, $table): bool {
				$this->catalog->tables()->update(
					$object,
					$table->register,
					$table->schema,
					$before
				);
				$this->audit->createAuditTrail(old: $before, new: $object, action: 'update');

				return true;
			}
		);
	}//end moveObject()
}//end class
