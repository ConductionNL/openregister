<?php

/**
 * An entry point: this flow starts at a moment derived from a date on a record.
 *
 * The calendar sibling of {@see TriggerScheduleNode}: a contract's end date
 * minus thirty days, a permit's expiry, an appointment plus a week. The node
 * names the register, the schema, the date property and the offset; the
 * due-moment index, the background job that queues the runs, and counting the
 * offset in a working calendar follow in the same change. This class is the
 * declaration and the config check that refuses a trigger which could never
 * fire as written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateInterval;
use InvalidArgumentException;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCP\IUserManager;
use Throwable;

/**
 * Starts the flow at a record's date plus an offset.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers
 */
class TriggerCalendarNode extends TriggerNodeBase implements TriggerCalendarNodeInterface, IFlowNodeConfigForm {

	/**
	 * The schema formats a date property may have.
	 */
	public const DATE_FORMATS = ['date', 'date-time'];

	/**
	 * Constructor.
	 *
	 * @param FlowNodePresentation $presentation The translator and URL generator.
	 * @param IUserManager         $userManager  Resolves the acting identity.
	 * @param SchemaMapper         $schemaMapper Resolves the schema the date lives on.
	 */
	public function __construct(
		FlowNodePresentation $presentation,
		private readonly IUserManager $userManager,
		private readonly SchemaMapper $schemaMapper,
	) {
		parent::__construct(l10n: $presentation->l10n, urls: $presentation->urls);
	}//end __construct()

	/**
	 * The node type id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers
	 */
	public function getId(): string {
		return 'openregister.trigger-calendar';
	}//end getId()

	/**
	 * The name in the palette.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('At a date on a record');
	}//end getDisplayName()

	/**
	 * What the node does.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Start the flow at a date on each record plus an offset, such as thirty days before a contract ends. '
				. 'The record is the run\'s subject.'
		);
	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'places/calendar.svg');
	}//end getIcon()

	/**
	 * The config keys this node reads.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers
	 */
	public function configKeys(): array {
		return ['register', 'schema', 'dateProperty', 'offset', 'workingCalendar', 'runAs'];
	}//end configKeys()

	/**
	 * The config form.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers
	 */
	public function configForm(): array {
		return [
			['key' => 'register', 'label' => $this->l10n->t('Register'), 'type' => 'text', 'required' => true],
			['key' => 'schema', 'label' => $this->l10n->t('Schema'), 'type' => 'text', 'required' => true],
			[
				'key' => 'dateProperty',
				'label' => $this->l10n->t('Date property'),
				'type' => 'text',
				'help' => $this->l10n->t('A property of the schema with the format date or date-time.'),
				'required' => true,
			],
			[
				'key' => 'offset',
				'label' => $this->l10n->t('Offset'),
				'type' => 'text',
				'help' => $this->l10n->t('An ISO 8601 duration from the date: "-P30D" is thirty days before, "P1W" a week after, "P0D" the date itself.'),
				'required' => true,
			],
			[
				'key' => 'workingCalendar',
				'label' => $this->l10n->t('Working calendar'),
				'type' => 'text',
				'help' => $this->l10n->t('Optional: count the offset in this calendar\'s working time.'),
			],
			[
				'key' => 'runAs',
				'label' => $this->l10n->t('Run as user'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'The account the runs act as. Nobody is present when a date comes due, '
						. 'and the flow\'s author is never used in its place.'
				),
				'required' => true,
			],
		];
	}//end configForm()

	/**
	 * Refuse a config that could never fire as written.
	 *
	 * @param array<string, mixed> $config The node's config.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException With a message naming what is wrong.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md
	 */
	public function validateConfig(array $config): void {
		$register = trim((string)($config['register'] ?? ''));
		$schema = trim((string)($config['schema'] ?? ''));
		if ($register === '' || $schema === '') {
			throw new InvalidArgumentException('A calendar trigger must name the "register" and the "schema" whose records carry the date.');
		}

		$this->assertDateProperty(schema: $schema, property: trim((string)($config['dateProperty'] ?? '')));
		self::assertOffset(offset: trim((string)($config['offset'] ?? '')));

		$calendar = ($config['workingCalendar'] ?? null);
		if ($calendar !== null && is_string($calendar) === false) {
			throw new InvalidArgumentException('A calendar trigger\'s "workingCalendar" names a calendar by its slug, as text.');
		}

		$this->assertActingIdentity(runAs: trim((string)($config['runAs'] ?? '')));
	}//end validateConfig()

	/**
	 * Refuse a date property that the schema does not declare as a date.
	 *
	 * @param string $schema   The schema id or slug.
	 * @param string $property The property name.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is missing or not a date.
	 */
	private function assertDateProperty(string $schema, string $property): void {
		if ($property === '') {
			throw new InvalidArgumentException('A calendar trigger must name the "dateProperty" whose value the moment is counted from.');
		}

		try {
			$properties = (array)$this->schemaMapper->find(id: $schema)->getProperties();
		} catch (Throwable $e) {
			throw new InvalidArgumentException(sprintf('The calendar trigger\'s schema "%s" cannot be found.', $schema));
		}

		$definition = ($properties[$property] ?? null);
		if (is_array($definition) === false) {
			throw new InvalidArgumentException(sprintf('The schema "%s" has no property "%s".', $schema, $property));
		}

		if (in_array(($definition['format'] ?? null), self::DATE_FORMATS, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'The calendar trigger\'s "dateProperty" names "%s", which is not a date. It accepts a property with the format %s.',
					$property,
					implode(' or ', self::DATE_FORMATS)
				)
			);
		}
	}//end assertDateProperty()

	/**
	 * Refuse an offset that is not an ISO 8601 duration, optionally negative.
	 *
	 * @param string $offset The offset.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it does not parse.
	 */
	private static function assertOffset(string $offset): void {
		$duration = $offset;
		if (str_starts_with($duration, '-') === true) {
			$duration = substr($duration, 1);
		}

		try {
			new DateInterval($duration);
			$parsed = ($duration !== '');
		} catch (Throwable $e) {
			$parsed = false;
		}

		if ($parsed === false) {
			throw new InvalidArgumentException(
				sprintf('The calendar trigger\'s "offset" must be an ISO 8601 duration such as "-P30D" or "P1W"; "%s" is not.', $offset)
			);
		}
	}//end assertOffset()

	/**
	 * Refuse a missing or unknown acting identity.
	 *
	 * @param string $runAs The user id.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When there is none or it does not exist.
	 */
	private function assertActingIdentity(string $runAs): void {
		if ($runAs === '') {
			throw new InvalidArgumentException(
				'A calendar trigger must carry a "runAs" naming the user its runs act as. Nobody is present when a date comes due, '
				. 'and the flow\'s owner is not used as a fallback.'
			);
		}

		if ($this->userManager->get($runAs) === null) {
			throw new InvalidArgumentException(
				sprintf('The calendar trigger\'s "runAs" names "%s", which is not an existing user account.', $runAs)
			);
		}
	}//end assertActingIdentity()
}//end class
