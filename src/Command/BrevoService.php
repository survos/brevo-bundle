<?php

declare(strict_types=1);

namespace Survos\BrevoBundle\Command;

use Brevo\Brevo;
use Brevo\Contacts\Requests\CreateContactRequest;
use Brevo\Contacts\Requests\GetListsRequest;
use Brevo\Contacts\Requests\UpdateContactRequest;
use Brevo\EmailCampaigns\Requests\GetEmailCampaignsRequest;
use Brevo\Exceptions\BrevoApiException;
use Brevo\TransactionalEmails\Requests\GetEmailEventReportRequest;
use Brevo\TransactionalEmails\Requests\GetTransacBlockedContactsRequest;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Account, lists, campaigns and the transactional blocklist, from the service layer and the CLI.
 *
 * Lists and campaigns are composed in Brevo's UI; this bundle reads them and pushes contacts.
 * It knows nothing about consent: callers (e.g. outreach-bundle's SendPolicy) decide who is pushed.
 */
final class BrevoService
{
    public function __construct(
        private readonly Brevo $brevo,
        #[\SensitiveParameter] private readonly string $apiKey,
    ) {
    }

    // Brevo rejects a page size above its maximum with 400 out_of_range, so every limit
    // below is clamped: lists 50, blocked 100, campaigns 100, event report 5000.

    /** Brevo's own error text; the SDK's exception message is only "API request failed". */
    public static function errorMessage(BrevoApiException $e): string
    {
        $body = $e->getBody();
        $decoded = is_string($body) ? json_decode($body, true) : $body;

        return sprintf('%d %s', $e->getCode(), is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : $e->getMessage());
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey;
    }

    public function sdk(): Brevo
    {
        return $this->brevo;
    }

    /** @return list<array{id: int, name: string, subscribers: int, blocklisted: int, folderId: int}> */
    public function lists(int $limit = 50): array
    {
        $response = $this->brevo->contacts->getLists(new GetListsRequest(['limit' => min($limit, 50)]));

        return array_map(static fn ($list) => [
            'id' => $list->id,
            'name' => $list->name,
            'subscribers' => $list->uniqueSubscribers,
            'blocklisted' => $list->totalBlacklisted,
            'folderId' => $list->folderId,
        ], $response?->lists ?? []);
    }

    /** @return list<array{id: int, name: string, status: string, subject: ?string, createdAt: string}> */
    public function campaigns(?string $status = null, int $limit = 50): array
    {
        $response = $this->brevo->emailCampaigns->getEmailCampaigns(new GetEmailCampaignsRequest(array_filter([
            'status' => $status,
            'limit' => min($limit, 100),
            'excludeHtmlContent' => true,
        ], static fn ($v) => null !== $v)));

        return array_map(static fn ($campaign) => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'subject' => $campaign->subject,
            'createdAt' => $campaign->createdAt,
        ], $response?->campaigns ?? []);
    }

    /**
     * Create or update a contact and add it to lists. Never clears Brevo's blocklist flag:
     * a contact Brevo has blocklisted stays blocklisted.
     *
     * @param list<int>            $listIds
     * @param array<string, mixed> $attributes
     */
    public function upsertContact(string $email, array $listIds = [], array $attributes = []): void
    {
        $this->brevo->contacts->createContact(new CreateContactRequest(array_filter([
            'email' => strtolower(trim($email)),
            'listIds' => $listIds ?: null,
            'attributes' => $attributes ?: null,
            'updateEnabled' => true,
        ], static fn ($v) => null !== $v)));
    }

    /** @param list<int> $listIds */
    public function removeFromLists(string $email, array $listIds): void
    {
        $this->brevo->contacts->updateContact(strtolower(trim($email)), new UpdateContactRequest(['unlinkListIds' => $listIds]));
    }

    /** @return list<array{email: string, reason: ?string, blockedAt: string, sender: ?string}> */
    public function blockedContacts(int $limit = 100): array
    {
        $response = $this->brevo->transactionalEmails->getTransacBlockedContacts(new GetTransacBlockedContactsRequest(['limit' => min($limit, 100)]));

        return array_map(static fn ($contact) => [
            'email' => $contact->email,
            'reason' => $contact->reason->code,
            'blockedAt' => $contact->blockedAt,
            'sender' => $contact->senderEmail,
        ], $response?->contacts ?? []);
    }

    /**
     * Brevo's own transactional event log: requests, deliveries, opens, bounces… newest first.
     * Nothing is stored locally; this is what Brevo has.
     *
     * @return list<array{date: string, email: string, event: string, subject: ?string, reason: ?string, messageId: string}>
     */
    public function events(?string $email = null, ?string $event = null, int $days = 7, int $limit = 100): array
    {
        $response = $this->brevo->transactionalEmails->getEmailEventReport(new GetEmailEventReportRequest(array_filter([
            'email' => $email,
            'event' => $event,
            'days' => $days,
            'limit' => min($limit, 5000),
            'sort' => 'desc',
        ], static fn ($v) => null !== $v)));

        return array_map(static fn ($e) => [
            'date' => $e->date,
            'email' => $e->email,
            'event' => $e->event,
            'subject' => $e->subject,
            'reason' => $e->reason,
            'messageId' => $e->messageId,
        ], $response?->events ?? []);
    }

    #[AsCommand('brevo:events', 'Show Brevo\'s transactional event log (delivered, opened, bounced…)')]
    public function eventsCommand(
        SymfonyStyle $io,
        #[Option('Only this address')] ?string $email = null,
        #[Option('delivered, hardBounces, softBounces, opened, clicks, spam, unsubscribed, blocked…')] ?string $event = null,
        #[Option('How many days back')] int $days = 7,
        #[Option('How many events')] int $limit = 50,
    ): int {
        $rows = $this->events($email, $event, $days, $limit);
        $io->table(['date', 'email', 'event', 'subject', 'reason', 'message id'], array_map(array_values(...), $rows));

        return Command::SUCCESS;
    }

    #[AsCommand('brevo:status', 'Show the Brevo account, plan and credits for BREVO_API_KEY')]
    public function status(SymfonyStyle $io): int
    {
        if (!$this->isConfigured()) {
            $io->error('BREVO_API_KEY is empty. Set it in .env.local (dev) or with `dokku config:set --no-restart` (prod).');

            return Command::FAILURE;
        }
        try {
            $account = $this->brevo->account->getAccount();
        } catch (BrevoApiException $e) {
            $io->error(sprintf('Brevo refused the key: %s', self::errorMessage($e)));

            return Command::FAILURE;
        }
        $io->definitionList(
            ['Company' => $account?->companyName],
            ['Login' => $account?->email],
            ...array_map(static fn ($plan) => [sprintf('Plan (%s)', $plan->type) => sprintf('%s %s', $plan->credits, $plan->creditsType)], $account?->plan ?? []),
        );

        return Command::SUCCESS;
    }

    #[AsCommand('brevo:lists', 'List Brevo contact lists with subscriber counts')]
    public function listsCommand(SymfonyStyle $io, #[Option('How many lists')] int $limit = 50): int
    {
        $rows = $this->lists($limit);
        $io->table(['id', 'name', 'subscribers', 'blocklisted', 'folder'], array_map(array_values(...), $rows));

        return Command::SUCCESS;
    }

    #[AsCommand('brevo:campaigns', 'List Brevo email campaigns')]
    public function campaignsCommand(
        SymfonyStyle $io,
        #[Option('draft, sent, archive, queued, suspended, inProcess')] ?string $status = null,
        #[Option('How many campaigns')] int $limit = 50,
    ): int {
        $rows = $this->campaigns($status, $limit);
        $io->table(['id', 'name', 'status', 'subject', 'created'], array_map(array_values(...), $rows));

        return Command::SUCCESS;
    }

    #[AsCommand('brevo:blocked', 'List addresses Brevo blocks for transactional mail (bounces, complaints, unsubscribes)')]
    public function blockedCommand(SymfonyStyle $io, #[Option('How many')] int $limit = 100): int
    {
        $rows = $this->blockedContacts($limit);
        $io->table(['email', 'reason', 'blocked at', 'sender'], array_map(array_values(...), $rows));

        return Command::SUCCESS;
    }

    #[AsCommand('brevo:contact:push', 'Create or update a Brevo contact and add it to lists. The caller is responsible for consent.')]
    public function pushCommand(
        SymfonyStyle $io,
        #[Argument('Email address')] string $email,
        #[Option('Brevo list id (repeatable)')] array $list = [],
    ): int {
        $this->upsertContact($email, array_map(intval(...), $list));
        $io->success(sprintf('%s pushed%s', $email, $list ? ' to list(s) '.implode(', ', $list) : ''));

        return Command::SUCCESS;
    }
}
