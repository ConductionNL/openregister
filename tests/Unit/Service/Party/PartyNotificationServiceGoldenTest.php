<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutQuestion;
use OCA\OpenRegister\Notifications\Service\Party\PartyMessage;
use OCA\OpenRegister\Notifications\Service\Party\PartyNotificationService;
use OCA\OpenRegister\Notifications\Service\Party\PartyReachGates;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Notifications\Service\Notification\MailAddressee;
use OCA\OpenRegister\Notifications\Service\Notification\MailContent;

/**
 * Golden: who notifyParties() mails and who it refuses, for one object holding every kind of party.
 *
 * Allowed: a party integriq lets through. Refused: a refuse-send indicator,
 * no address, an opt-out, an unavailable authority, and a decision whose
 * `send` is anything but boolean true.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Party\PartyNotificationService
 */
class PartyNotificationServiceGoldenTest extends TestCase
{

    /**
     * Each call in order: the opt-out questions, the link lines and the mails.
     *
     * @var array<int, array<int, mixed>>
     */
    private array $log = [];

    /**
     * The decision per address the opt-out authority returns.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $decisions = [];

    /**
     * The service over parties keyed by uuid: [role, address|null, refusal|null].
     *
     * @param array<string, array<int, string|null>> $parties The parties.
     *
     * @return PartyNotificationService The service.
     */
    private function service(array $parties): PartyNotificationService
    {
        $links = [];
        foreach ($parties as $uuid => [$role]) {
            $link = new ContactLink();
            $link->setPartyUuid($uuid);
            $link->setRole($role);
            $link->setDisplayName('Name ' . $uuid);
            $links[] = $link;
        }

        $empty = new ContactLink();
        $empty->setPartyUuid('');
        $links[] = $empty;

        $linkMapper = $this->createMock(ContactLinkMapperInterface::class);
        $linkMapper->method('findPartiesForObject')->willReturn($links);

        $partyService = $this->createMock(PartyServiceInterface::class);
        $partyService->method('find')->willReturnCallback(
            static function (string $partyUuid): ObjectEntity {
                $party = new ObjectEntity();
                $party->setUuid($partyUuid);
                return $party;
            }
        );
        $partyService->method('definitionFor')->willReturn(null);
        $partyService->method('addressesOfKind')->willReturnCallback(
            static function (ObjectEntity $party) use ($parties): array {
                $address = $parties[(string)$party->getUuid()][1];
                if ($address === null) {
                    return [];
                }

                return [['kind' => 'correspondence', 'type' => 'email', 'value' => $address, 'label' => null]];
            }
        );
        $partyService->method('addresses')->willReturn([['kind' => 'other', 'type' => 'phone', 'value' => '0612345678', 'label' => null]]);

        $indicators = $this->createMock(PartyIndicatorGuard::class);
        $indicators->method('sendRefusalFor')->willReturnCallback(
            static fn (string $partyUuid): ?string => $parties[$partyUuid][2]
        );

        $optOut = $this->createMock(OptOutAuthority::class);
        $optOut->method('askQuestion')->willReturnCallback(
            function (OptOutQuestion $question): array {
                $this->log[] = ['ask', $question->channel, $question->category, $question->addresses, $question->correlationId];
                return $this->decisions;
            }
        );
        $optOut->method('decisionFor')->willReturnCallback(
            static fn (array $decisions, string $address): array => ($decisions[$address] ?? ['send' => false, 'code' => OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE, 'unsubscribe' => null])
        );
        $optOut->method('withLink')->willReturnCallback(
            static fn (string $body, ?array $unsubscribe): string => $body . '|' . ($unsubscribe['url'] ?? 'none')
        );

        $email = $this->createMock(EmailSender::class);
        $email->method('sendMailTo')->willReturnCallback(
            function (MailAddressee $to, MailContent $mail): string {
                $this->log[] = ['mail', $to->address, $to->displayName, $mail->subject, $mail->body, $mail->unsubscribe];
                return EmailSender::OUTCOME_DISPATCHED;
            }
        );

        return new PartyNotificationService($linkMapper, $partyService, $email, new PartyReachGates(indicators: $indicators, optOut: $optOut));
    }

    /**
     * The mixed object: one party of every outcome.
     *
     * @return array<string, array<int, string|null>> The parties.
     */
    private function everyKindOfParty(): array
    {
        return [
            'p-indicator' => ['aanvrager', 'ind@example.org', 'Geen post'],
            'p-noaddress' => ['aanvrager', null, null],
            'p-optedout'  => ['aanvrager', 'out@example.org', null],
            'p-allowed'   => ['aanvrager', 'ok@example.org', null],
            'p-truthy'    => ['aanvrager', 'truthy@example.org', null],
            'p-unknown'   => ['aanvrager', 'unknown@example.org', null],
            'p-other'     => ['gemachtigde', 'other@example.org', null],
        ];
    }

    public function testEveryOutcomeOnOneObject(): void
    {
        $this->decisions = [
            'out@example.org'    => ['send' => false, 'code' => 'opted-out', 'unsubscribe' => null],
            'ok@example.org'     => ['send' => true, 'code' => 'allowed', 'unsubscribe' => ['url' => 'https://u/ok']],
            'truthy@example.org' => ['send' => 1, 'code' => 'allowed', 'unsubscribe' => ['url' => 'https://u/t']],
            'other@example.org'  => ['send' => true, 'code' => 'allowed', 'unsubscribe' => null],
        ];

        $outcome = $this->service($this->everyKindOfParty())->notifyParties(new PartyMessage('case-9', 'Subject', 'Body', 'case-update'), 'aanvrager');

        $this->assertSame(
            [
                ['party' => 'p-indicator', 'address' => null, 'outcome' => 'refused-by-indicator'],
                ['party' => 'p-noaddress', 'address' => null, 'outcome' => EmailSender::OUTCOME_NO_ADDRESS],
                ['party' => 'p-optedout', 'address' => null, 'outcome' => PartyNotificationService::OUTCOME_REFUSED_OPTED_OUT],
                ['party' => 'p-allowed', 'address' => 'ok@example.org', 'outcome' => EmailSender::OUTCOME_DISPATCHED],
                ['party' => 'p-truthy', 'address' => null, 'outcome' => PartyNotificationService::OUTCOME_REFUSED_OPTED_OUT],
                ['party' => 'p-unknown', 'address' => null, 'outcome' => PartyNotificationService::OUTCOME_AUTHORITY_UNAVAILABLE],
            ],
            $outcome
        );
        $this->assertSame(
            [
                ['ask', 'email', 'case-update', ['out@example.org', 'ok@example.org', 'truthy@example.org', 'unknown@example.org'], 'openregister-parties:case-9'],
                ['mail', 'ok@example.org', 'Name p-allowed', 'Subject', 'Body|https://u/ok', ['url' => 'https://u/ok']],
            ],
            $this->log
        );
    }

    public function testWithoutRoleEveryPartyIsAskedWithTheDefaultCategory(): void
    {
        $this->decisions = ['other@example.org' => ['send' => true, 'code' => 'allowed', 'unsubscribe' => null]];

        $outcome = $this->service($this->everyKindOfParty())->notifyParties(new PartyMessage('case-9', 'S', 'B'));

        $this->assertSame(OptOutAuthority::DEFAULT_CATEGORY, $this->log[0][2]);
        $this->assertCount(7, $outcome);
        $this->assertSame(['party' => 'p-other', 'address' => 'other@example.org', 'outcome' => EmailSender::OUTCOME_DISPATCHED], $outcome[6]);
        $this->assertSame(['mail', 'other@example.org', 'Name p-other', 'S', 'B|none', null], $this->log[1]);
        $this->assertCount(2, $this->log);
    }

    public function testNoAskablePartyStillAsksWithAnEmptyList(): void
    {
        $outcome = $this->service(['p-indicator' => ['aanvrager', 'ind@example.org', 'Geen post']])->notifyParties(new PartyMessage('case-1', 'S', 'B'));

        $this->assertSame([['ask', 'email', OptOutAuthority::DEFAULT_CATEGORY, [], 'openregister-parties:case-1']], $this->log);
        $this->assertSame([['party' => 'p-indicator', 'address' => null, 'outcome' => 'refused-by-indicator']], $outcome);
    }
}
