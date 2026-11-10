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
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	 * @param OrganisationMapperInterface $organisationMapper The Organisation mapper.
	 * @param ObjectOperationsInterface      $objectService      Reader for the leaf objects.
	 * @param LoggerInterface    $logger             Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public function __construct(
		private readonly OrganisationMapperInterface $organisationMapper,
		private readonly ObjectOperationsInterface $objectService,
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

		$rows = $this->readRows(input: $input, output: $output);
		if ($rows === null) {
			return Command::FAILURE;
		}

		$tally = $this->adoptAll(run: new AdoptionRun(output: $output, dryRun: $dryRun), rows: $rows);

		$output->writeln(self::summaryLine(tally: $tally, dryRun: $dryRun));

		$this->logger->info(
			'OpenRegister: adopted leaf organisations',
			[
				'register' => $registerSlug,
				'schema' => $schemaSlug,
				'adopted' => $tally['adopted'],
				'merged' => $tally['merged'],
				'skipped' => $tally['skipped'],
				'failed' => $tally['failed'],
				'dryRun' => $dryRun,
			]
		);

		if ($tally['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Read every leaf row, or report why they could not be read.
	 *
	 * @param InputInterface  $input  Console input naming the register and the leaf schema.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return array<int|string, mixed>|null The rows, or null when the command must fail.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private function readRows(InputInterface $input, OutputInterface $output): ?array {
		$registerSlug = (string)$input->getOption('register');
		$schemaSlug = (string)$input->getOption('schema');

		try {
			$rows = $this->objectService->searchObjectsBySlug(
				$registerSlug,
				$schemaSlug,
				['_limit' => false],
				false,
				false
			);
		} catch (Throwable $e) {
			$output->writeln(sprintf('<error>Could not read %s/%s: %s</error>', $registerSlug, $schemaSlug, $e->getMessage()));
			return null;
		}

		if (is_array($rows) === false) {
			$output->writeln('<error>The object reader returned a count rather than rows.</error>');
			return null;
		}

		return $rows;
	}//end readRows()

	/**
	 * Adopt every row against the organisations already on the instance.
	 *
	 * @param AdoptionRun              $run  The adoption in progress.
	 * @param array<int|string, mixed> $rows The leaf rows.
	 *
	 * @return array{adopted:int, merged:int, skipped:int, failed:int} The run's tally.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private function adoptAll(AdoptionRun $run, array $rows): array {
		$run->existing = $this->existingOrganisations();

		foreach ($rows as $row) {
			$this->adoptRow(run: $run, row: $row);
		}

		return $run->tally;
	}//end adoptAll()

	/**
	 * The closing summary line.
	 *
	 * @param array{adopted:int, merged:int, skipped:int, failed:int} $tally  The run's tally.
	 * @param bool                                                    $dryRun Whether nothing was written.
	 *
	 * @return string The line to print.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private static function summaryLine(array $tally, bool $dryRun): string {
		$suffix = '';
		if ($dryRun === true) {
			$suffix = ' (dry run — nothing written)';
		}

		return sprintf(
			'<info>Done. Adopted=%d (of which merged=%d), skipped=%d, failed=%d%s</info>',
			$tally['adopted'],
			$tally['merged'],
			$tally['skipped'],
			$tally['failed'],
			$suffix
		);
	}//end summaryLine()

	/**
	 * Adopt one leaf row, counting what happened to it on the run.
	 *
	 * @param AdoptionRun $run The adoption in progress; its candidates grow as rows are adopted.
	 * @param mixed       $row The row as the reader returned it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private function adoptRow(AdoptionRun $run, mixed $row): void {
		$fields = self::toFields(row: $row);

		$skip = self::skipLine(uuid: (string)($fields['uuid'] ?? ''), existing: $run->existing);
		if ($skip !== null) {
			$run->output->writeln($skip);
			$run->tally['skipped']++;
			return;
		}

		$leaf = new LeafMatch(fields: $fields, target: self::findMergeTarget(row: $fields, existing: array_values($run->existing)));

		$this->reportCandidate(leaf: $leaf, output: $run->output);

		if ($run->dryRun === true) {
			$run->output->writeln('  <comment>WOULD ADOPT</comment>');
			return;
		}

		$this->insertRow(run: $run, leaf: $leaf);
	}//end adoptRow()

	/**
	 * Why a row is skipped before any matching: no uuid, or already adopted.
	 *
	 * @param string                              $uuid     The row's uuid.
	 * @param array<string, array<string, mixed>> $existing Organisations already on the instance.
	 *
	 * @return string|null The line to print, or null when the row is adopted.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private static function skipLine(string $uuid, array $existing): ?string {
		if ($uuid === '') {
			return '  <comment>SKIP a row with no uuid: it has no idempotency key.</comment>';
		}

		if (isset($existing[$uuid]) === true) {
			return sprintf('  <comment>SKIP %s: already adopted.</comment>', $uuid);
		}

		return null;
	}//end skipLine()

	/**
	 * Report the row about to be adopted, its merge and its dropped properties.
	 *
	 * @param LeafMatch       $leaf   The row and the organisation it merges into, if any.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private function reportCandidate(LeafMatch $leaf, OutputInterface $output): void {
		$mergeNote = '';
		if ($leaf->target !== null) {
			$mergeNote = sprintf(' -> merges into %s', $leaf->target['uuid']);
		}

		$output->writeln(
			sprintf('<info>%s (%s)%s</info>', $leaf->uuid(), (string)($leaf->fields['name'] ?? 'unnamed'), $mergeNote)
		);

		$this->reportUndeclared(fields: $leaf->fields, output: $output);
	}//end reportCandidate()

	/**
	 * Insert one adopted row and add it to the candidates for later rows.
	 *
	 * @param AdoptionRun $run  The adoption in progress; its candidates grow.
	 * @param LeafMatch   $leaf The row and the organisation it merges into, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private function insertRow(AdoptionRun $run, LeafMatch $leaf): void {
		try {
			$saved = $this->organisationMapper->insert(
				self::buildOrganisation(fields: $leaf->fields, mergeTarget: $leaf->target)
			);
		} catch (Throwable $e) {
			$run->output->writeln(sprintf('  <error>FAILED: %s</error>', $e->getMessage()));
			$run->tally['failed']++;
			return;
		}

		$run->existing[$leaf->uuid()] = self::toCandidate(organisation: $saved);
		$run->output->writeln('  <info>ADOPTED</info>');

		$run->tally['adopted']++;
		if ($leaf->target !== null) {
			$run->tally['merged']++;
		}
	}//end insertRow()

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

			$matches = array_values(
				array_filter(
					$existing,
					static fn (array $candidate): bool => self::normaliseIdentifier(value: ($candidate[$field] ?? null)) === $needle
				)
			);
			if ($matches === []) {
				continue;
			}

			usort($matches, self::compareCandidates(...));

			return $matches[0];
		}

		return null;
	}//end findMergeTarget()

	/**
	 * Order candidates: live before merged-away, then lowest id first.
	 *
	 * @param array<string, mixed> $a A candidate.
	 * @param array<string, mixed> $b Another candidate.
	 *
	 * @return int The comparison result.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private static function compareCandidates(array $a, array $b): int {
		$aMerged = (int)(($a['mergedInto'] ?? null) !== null);
		$bMerged = (int)(($b['mergedInto'] ?? null) !== null);

		if ($aMerged !== $bMerged) {
			return ($aMerged <=> $bMerged);
		}

		return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
	}//end compareCandidates()

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
			if (self::isReservedProperty(property: $property) === true) {
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
	 * Whether a property is metadata or an identity key, never carried as a field.
	 *
	 * @param mixed $property The property name.
	 *
	 * @return bool True for `@`-prefixed names, `id` and `uuid`.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private static function isReservedProperty(mixed $property): bool {
		return (str_starts_with($property, '@') === true || $property === 'id' || $property === 'uuid');
	}//end isReservedProperty()

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

		$row['uuid'] = self::resolveUuid(row: $row);

		return $row;
	}//end toFields()

	/**
	 * The row's uuid: from `@self` first, then from the row itself.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or '' when the row carries none.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private static function resolveUuid(array $row): string {
		$self = ($row['@self'] ?? []);
		$uuid = '';
		if (is_array($self) === true) {
			$uuid = (string)($self['uuid'] ?? ($self['id'] ?? ''));
		}

		if ($uuid === '') {
			$uuid = (string)($row['uuid'] ?? ($row['id'] ?? ''));
		}

		return $uuid;
	}//end resolveUuid()

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

		self::applyFields(organisation: $organisation, fields: $fields);

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
	 * Copy every declared, non-empty scalar field onto the organisation.
	 *
	 * @param Organisation         $organisation The organisation being built.
	 * @param array<string, mixed> $fields       The leaf row's fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	private static function applyFields(Organisation $organisation, array $fields): void {
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
	}//end applyFields()

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
