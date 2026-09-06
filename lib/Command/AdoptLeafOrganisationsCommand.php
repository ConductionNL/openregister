<?php

/**
 * OpenRegister adopt-leaf-organisations command
 *
 * Moves a leaf app's own `organization` objects onto OpenRegister's
 * Organisation, which is the single home for the entity.
 *
 * Several apps grew their own organisation schema before OR's Organisation
 * carried the fields they needed. The slug is global per organisation, so those
 * copies collide: `SchemaMapper::find()` matches on `LOWER(slug)` across every
 * app and hands back whichever row it reaches first. Adding the columns to
 * Organisation made reuse possible; it did not move anybody's rows.
 *
 * This command moves them. Two rules govern it, and both were learned rather
 * than chosen:
 *
 * 1. The UUID is the idempotency key, never the slug or the name. Two leaf rows
 *    can legitimately share a name, and keying on it would skip the second as
 *    "already migrated" and silently merge two distinct legal entities.
 * 2. The uuid is PRESERVED onto the adopted Organisation, so every reference
 *    stored anywhere still resolves.
 *
 * Where the same legal entity already exists in OR under a different uuid, the
 * rows are not collapsed into one. The adopted row is created and pointed at
 * the existing one through `mergedInto`, so both uuids keep resolving and the
 * merge is a fact recorded on a row rather than data thrown away. Matching is
 * on a legal identifier (OIN, then RSIN, then KVK) and never on a name.
 *
 * Dry-run by default. Pass --apply to write.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use DateTime;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Adopt a leaf app's organisation objects into OpenRegister's Organisation.
 *
 * @spec openspec/changes/consolidate-organisation-on-or/tasks.md#5-leaf-app-consolidation
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The spread is the point: each
 * branch is one rule about what an adoption keeps, merges or drops, and the
 * class is at 53 against a threshold of 50. Collapsing branches to satisfy the
 * number would hide the rules rather than simplify them, and every one of them
 * is pinned by its own test.
 */
class AdoptLeafOrganisationsCommand extends Command {
	/**
	 * The legal identifiers a match may be made on, in precedence order.
	 *
	 * A name is deliberately absent. Two organisations sharing a name are
	 * routine; two sharing an OIN are the same body.
	 *
	 * @var array<int, string>
	 */
	private const LEGAL_IDENTIFIERS = ['oin', 'rsin', 'kvk'];

	/**
	 * Leaf property name to Organisation setter suffix.
	 *
	 * Only properties Organisation actually declares are listed. Anything the
	 * leaf schema carries beyond these is reported rather than dropped quietly,
	 * because a property OR does not declare is a property OR discards.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_MAP = [
		'name' => 'Name',
		'summary' => 'Summary',
		'description' => 'Description',
		'oin' => 'Oin',
		'tooi' => 'Tooi',
		'rsin' => 'Rsin',
		'kvk' => 'Kvk',
		'pki' => 'Pki',
		'image' => 'Image',
		'type' => 'Type',
		'status' => 'Status',
		'registrationStatus' => 'RegistrationStatus',
	];

	/**
	 * Wire the mappers and the object reader.
	 *
	 * @param OrganisationMapper $organisationMapper The Organisation mapper.
	 * @param ObjectService      $objectService      Reader for the leaf objects.
	 * @param LoggerInterface    $logger             Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public function __construct(
		private readonly OrganisationMapper $organisationMapper,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:organisations:adopt')
			->setDescription(
				'Adopt a leaf app\'s own organisation objects into OpenRegister\'s Organisation, '
				. 'preserving each uuid and recording a merge where the same legal entity already exists.'
			)
			->addOption(
				'register',
				null,
				InputOption::VALUE_REQUIRED,
				'The register slug holding the leaf organisation objects (for example `publication`).'
			)
			->addOption(
				'schema',
				null,
				InputOption::VALUE_REQUIRED,
				'The leaf schema slug to adopt from. Defaults to `organization`.',
				'organization'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Actually write. Without this flag the command reports what it WOULD do.'
			);
	}//end configure()

	/**
	 * Read the leaf rows and adopt each one.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$registerSlug = (string)$input->getOption('register');
		$schemaSlug = (string)$input->getOption('schema');
		$dryRun = ((bool)$input->getOption('apply') === false);

		if ($registerSlug === '') {
			$output->writeln('<error>--register is required.</error>');
			return Command::FAILURE;
		}

		if ($dryRun === true) {
			$output->writeln(
				'<comment>Running in DRY-RUN mode — nothing will be written. '
				. 'Re-run with --apply to adopt.</comment>'
			);
		}

		try {
			$rows = $this->objectService->searchObjectsBySlug(
				$registerSlug,
				$schemaSlug,
				['_limit' => 5000],
				false,
				false
			);
		} catch (Throwable $e) {
			$output->writeln(sprintf('<error>Could not read %s/%s: %s</error>', $registerSlug, $schemaSlug, $e->getMessage()));
			return Command::FAILURE;
		}

		if (is_array($rows) === false) {
			$output->writeln('<error>The object reader returned a count rather than rows.</error>');
			return Command::FAILURE;
		}

		$existing = $this->existingOrganisations();
		$adopted = 0;
		$merged = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($rows as $row) {
			$outcome = $this->adoptRow(
				row: $row,
				existing: $existing,
				dryRun: $dryRun,
				output: $output
			);

			$adopted += $outcome['adopted'];
			$merged += $outcome['merged'];
			$skipped += $outcome['skipped'];
			$failed += $outcome['failed'];
		}//end foreach

		$suffix = '';
		if ($dryRun === true) {
			$suffix = ' (dry run — nothing written)';
		}

		$output->writeln(
			sprintf(
				'<info>Done. Adopted=%d (of which merged=%d), skipped=%d, failed=%d%s</info>',
				$adopted,
				$merged,
				$skipped,
				$failed,
				$suffix
			)
		);

		$this->logger->info(
			'OpenRegister: adopted leaf organisations',
			[
				'register' => $registerSlug,
				'schema' => $schemaSlug,
				'adopted' => $adopted,
				'merged' => $merged,
				'skipped' => $skipped,
				'failed' => $failed,
				'dryRun' => $dryRun,
			]
		);

		if ($failed > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Adopt one leaf row, reporting what happened to it.
	 *
	 * @param mixed                                $row      The row as the reader returned it.
	 * @param array<string, array<string, mixed>>  $existing Organisations already on the instance,
	 *                                                       keyed by uuid. It grows as rows are
	 *                                                       adopted, so a legal identifier appearing
	 *                                                       twice within one run merges the second
	 *                                                       occurrence too.
	 * @param bool                                 $dryRun   Whether to report rather than write.
	 * @param OutputInterface                      $output   Console output stream.
	 *
	 * @return array{adopted:int, merged:int, skipped:int, failed:int} The tally for this row.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private function adoptRow(mixed $row, array &$existing, bool $dryRun, OutputInterface $output): array {
		$none = ['adopted' => 0, 'merged' => 0, 'skipped' => 0, 'failed' => 0];

		$fields = self::toFields(row: $row);
		$uuid = (string)($fields['uuid'] ?? '');

		if ($uuid === '') {
			$output->writeln('  <comment>SKIP a row with no uuid: it has no idempotency key.</comment>');
			return array_merge($none, ['skipped' => 1]);
		}

		if (isset($existing[$uuid]) === true) {
			$output->writeln(sprintf('  <comment>SKIP %s: already adopted.</comment>', $uuid));
			return array_merge($none, ['skipped' => 1]);
		}

		$target = self::findMergeTarget(row: $fields, existing: array_values($existing));

		$mergeNote = '';
		$mergedCount = 0;
		if ($target !== null) {
			$mergeNote = sprintf(' -> merges into %s', $target['uuid']);
			$mergedCount = 1;
		}

		$output->writeln(
			sprintf('<info>%s (%s)%s</info>', $uuid, (string)($fields['name'] ?? 'unnamed'), $mergeNote)
		);

		$this->reportUndeclared(fields: $fields, output: $output);

		if ($dryRun === true) {
			$output->writeln('  <comment>WOULD ADOPT</comment>');
			return $none;
		}

		try {
			$saved = $this->organisationMapper->insert(
				self::buildOrganisation(fields: $fields, mergeTarget: $target)
			);
		} catch (Throwable $e) {
			$output->writeln(sprintf('  <error>FAILED: %s</error>', $e->getMessage()));
			return array_merge($none, ['failed' => 1]);
		}

		$existing[$uuid] = self::toCandidate(organisation: $saved);
		$output->writeln('  <info>ADOPTED</info>');

		return array_merge($none, ['adopted' => 1, 'merged' => $mergedCount]);
	}//end adoptRow()

	/**
	 * Name the properties this adoption will not carry over.
	 *
	 * @param array<string, mixed> $fields The leaf row's fields.
	 * @param OutputInterface      $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private function reportUndeclared(array $fields, OutputInterface $output): void {
		$dropped = self::undeclaredProperties(row: $fields);
		if ($dropped === []) {
			return;
		}

		$phrase = sprintf('%d properties have', count($dropped));
		if (count($dropped) === 1) {
			$phrase = '1 property has';
		}

		$output->writeln(
			sprintf(
				'  <comment>%s no column on Organisation and will NOT be carried over: %s</comment>',
				$phrase,
				implode(', ', $dropped)
			)
		);
	}//end reportUndeclared()

	/**
	 * Normalise a legal identifier for comparison.
	 *
	 * The same OIN is written with and without spaces and dots depending on who
	 * typed it, so a literal comparison misses matches that are plainly the same
	 * body. Everything that is not a letter or a digit is dropped.
	 *
	 * @param mixed $value The stored identifier.
	 *
	 * @return string The comparable form, or '' when there is nothing to compare.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public static function normaliseIdentifier(mixed $value): string {
		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		$stripped = preg_replace('/[^a-z0-9]/i', '', (string)$value);
		if (is_string($stripped) === false) {
			return '';
		}

		return strtolower($stripped);
	}//end normaliseIdentifier()

	/**
	 * Find the organisation this row is the same legal entity as, if any.
	 *
	 * Matching runs on OIN, then RSIN, then KVK, and stops at the first
	 * identifier the row actually carries. A name is never matched on: two
	 * organisations sharing a name are routine, and collapsing them would
	 * destroy data that no later step can recover.
	 *
	 * Among several matches the LOWEST id is canonical, so a repeated run
	 * chooses the same survivor. A candidate that was itself merged away is
	 * deprioritised rather than excluded: pointing at it still resolves,
	 * because the resolver walks the chain.
	 *
	 * @param array<string, mixed>                     $row      The leaf row's fields.
	 * @param array<int, array<string, mixed>>         $existing Candidate organisations.
	 *
	 * @return array<string, mixed>|null The organisation to merge into, or null.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public static function findMergeTarget(array $row, array $existing): ?array {
		foreach (self::LEGAL_IDENTIFIERS as $field) {
			$needle = self::normaliseIdentifier(value: ($row[$field] ?? null));
			if ($needle === '') {
				continue;
			}

			$matches = [];
			foreach ($existing as $candidate) {
				if (self::normaliseIdentifier(value: ($candidate[$field] ?? null)) === $needle) {
					$matches[] = $candidate;
				}
			}

			if ($matches === []) {
				continue;
			}

			usort(
				$matches,
				static function (array $a, array $b) {
					$aMerged = 0;
					if (($a['mergedInto'] ?? null) !== null) {
						$aMerged = 1;
					}

					$bMerged = 0;
					if (($b['mergedInto'] ?? null) !== null) {
						$bMerged = 1;
					}

					if ($aMerged !== $bMerged) {
						return ($aMerged <=> $bMerged);
					}

					return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
				}
			);

			return $matches[0];
		}//end foreach

		return null;
	}//end findMergeTarget()

	/**
	 * The leaf properties Organisation has nowhere to put.
	 *
	 * OpenRegister discards a property its schema does not declare, and it does
	 * so with a 200 and the object back, so an adoption that loses fields looks
	 * exactly like one that did not. Naming them is the whole point.
	 *
	 * @param array<string, mixed> $row The leaf row's fields.
	 *
	 * @return array<int, string> The property names that will not be carried over.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public static function undeclaredProperties(array $row): array {
		$dropped = [];
		foreach (array_keys($row) as $property) {
			if (str_starts_with($property, '@') === true || $property === 'id' || $property === 'uuid') {
				continue;
			}

			if (isset(self::FIELD_MAP[$property]) === true) {
				continue;
			}

			$dropped[] = $property;
		}

		sort($dropped);

		return $dropped;
	}//end undeclaredProperties()

	/**
	 * Flatten an object row into a plain field map.
	 *
	 * @param mixed $row The row as the object reader returned it.
	 *
	 * @return array<string, mixed> The fields, with the uuid resolved.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public static function toFields(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		$self = ($row['@self'] ?? []);
		$uuid = '';
		if (is_array($self) === true) {
			$uuid = (string)($self['uuid'] ?? ($self['id'] ?? ''));
		}

		if ($uuid === '') {
			$uuid = (string)($row['uuid'] ?? ($row['id'] ?? ''));
		}

		$row['uuid'] = $uuid;

		return $row;
	}//end toFields()

	/**
	 * Build the Organisation to insert for one leaf row.
	 *
	 * @param array<string, mixed>      $fields      The leaf row's fields.
	 * @param array<string, mixed>|null $mergeTarget The organisation to merge into, if any.
	 *
	 * @return Organisation The organisation, not yet persisted.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public static function buildOrganisation(array $fields, ?array $mergeTarget = null): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid((string)$fields['uuid']);

		foreach (self::FIELD_MAP as $property => $suffix) {
			$value = ($fields[$property] ?? null);
			if ($value === null || $value === '') {
				continue;
			}

			if (is_scalar($value) === false) {
				continue;
			}

			$organisation->{'set' . $suffix}((string)$value);
		}

		// A slug is derived from the uuid rather than the name. Two adopted rows
		// can legitimately share a name, and a name-derived slug would collide.
		$organisation->setSlug('adopted-' . substr((string)$fields['uuid'], 0, 36));

		if ($mergeTarget !== null) {
			$organisation->setMergedInto((string)$mergeTarget['uuid']);
			$organisation->setMergedAt(new DateTime());
		}

		return $organisation;
	}//end buildOrganisation()

	/**
	 * Reduce an Organisation to the fields matching needs.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return array<string, mixed> The candidate record.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public static function toCandidate(Organisation $organisation): array {
		return [
			'id' => (int)$organisation->getId(),
			'uuid' => (string)$organisation->getUuid(),
			'oin' => $organisation->getOin(),
			'rsin' => $organisation->getRsin(),
			'kvk' => $organisation->getKvk(),
			'mergedInto' => $organisation->getMergedInto(),
		];
	}//end toCandidate()

	/**
	 * Every organisation already on the instance, keyed by uuid.
	 *
	 * @return array<string, array<string, mixed>> The candidates.
	 */
	private function existingOrganisations(): array {
		$candidates = [];
		foreach ($this->organisationMapper->findAll(limit: 10000, offset: 0, filters: []) as $organisation) {
			$candidates[(string)$organisation->getUuid()] = self::toCandidate(organisation: $organisation);
		}

		return $candidates;
	}//end existingOrganisations()
}//end class
