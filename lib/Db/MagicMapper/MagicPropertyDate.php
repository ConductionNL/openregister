<?php

/**
 * MagicPropertyDate: a date or date-time property value as its column stores it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use Psr\Container\ContainerInterface;

/**
 * A `date` or `date-time` property value as 'Y-m-d H:i:s' for a DATETIME column.
 *
 * The two differ. A date-time names an instant, so a non-UTC offset is applied before
 * storing (WOO-567). A date names a calendar day and has no instant, so converting it
 * through a timezone could move it: `2026-10-20T00:00:00+02:00` would become 2026-10-19
 * in UTC. A due date must survive being submitted from a client that sends an offset.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicPropertyDate {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the date normalizer.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(private readonly ContainerInterface $container) {
	}//end __construct()

	/**
	 * The stored form of a value; a value of another format is returned as is.
	 *
	 * @param mixed $value  The value.
	 * @param mixed $format The property's format.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function stored(mixed $value, mixed $format): mixed {
		if (in_array($format, ['date-time', 'date'], true) === false) {
			return $value;
		}

		if ($value instanceof DateTimeInterface) {
			return $this->fromMoment(value: $value, format: $format);
		}

		if (is_string($value) === false) {
			return $value;
		}

		return $this->fromText(value: $value, format: $format);
	}//end stored()

	/**
	 * The stored form of a DateTime.
	 *
	 * @param DateTimeInterface $value  The value.
	 * @param string            $format The property's format.
	 *
	 * @return string
	 */
	private function fromMoment(DateTimeInterface $value, string $format): string {
		$moment = DateTimeImmutable::createFromInterface($value);
		if ($format !== 'date') {
			$moment = $moment->setTimezone(new DateTimeZone(DateTimeNormalizer::DATABASE_TIMEZONE));
		}

		return $moment->format(DateTimeNormalizer::DATABASE_FORMAT);
	}//end fromMoment()

	/**
	 * The stored form of a date string.
	 *
	 * @param string $value  The value.
	 * @param string $format The property's format.
	 *
	 * @return mixed
	 */
	private function fromText(string $value, string $format): mixed {
		$normalizer = $this->container->get(DateTimeNormalizer::class);
		if ($format === 'date') {
			return $normalizer->formatDateForDatabase($value);
		}

		return $normalizer->formatForDatabase($value);
	}//end fromText()
}//end class
