# survos/brevo-bundle

Brevo for Symfony: the official SDK (`getbrevo/brevo-php`) wired onto Symfony HttpClient,
CLI commands for lists, campaigns and the blocklist, and Brevo webhooks re-dispatched as a
Symfony event any number of listeners can use.

It knows nothing about consent. Who may be pushed to a list or mailed is the caller's decision
(see `survos/outreach-bundle`'s `SendPolicy`).

## Install

```bash
composer require survos/brevo-bundle symfony/brevo-mailer symfony/webhook symfony/remote-event
```

```dotenv
# .env: committed, empty. Set the real key in .env.local (dev) or `dokku config:set --no-restart` (prod).
BREVO_API_KEY=
MAILER_DSN=brevo+api://${BREVO_API_KEY}@default
```

One key serves both sending (the mailer) and the SDK. Use the API transport, not
`smtp-relay.brevo.com`: the API returns Brevo's message id, which is what webhooks refer to.

## The SDK

`Brevo\Brevo` is a service built on the app's `http_client`, so calls show in the profiler.

```php
public function __construct(private Brevo $brevo) {}

$this->brevo->contacts->getLists();
```

`BrevoService` wraps the calls apps use most: `lists()`, `campaigns()`, `upsertContact()`,
`removeFromLists()`, `blockedContacts()`, `isConfigured()`. `upsertContact()` never clears
Brevo's blocklist flag.

## Commands

| Command | |
|---|---|
| `brevo:status` | account, plan, credits; says clearly when `BREVO_API_KEY` is empty |
| `brevo:lists` | contact lists with subscriber / blocklisted counts |
| `brevo:campaigns [--status=sent]` | email campaigns |
| `brevo:blocked` | addresses blocked for transactional mail and why |
| `brevo:contact:push EMAIL --list=2` | create/update a contact and add it to lists |

## Webhooks

With `symfony/webhook` and `symfony/brevo-mailer` installed, the bundle routes
`/webhook/brevo` to Symfony's Brevo parser. It consumes the resulting remote event and
dispatches `Survos\BrevoBundle\Event\BrevoMailEvent`:

```php
#[AsEventListener]
public function onBrevo(BrevoMailEvent $event): void
{
    if ($event->isSuppression) { // hard bounce, spam complaint, unsubscribe
        $this->suppress($event->email, $event->name, $event->messageId);
    }
}
```

In Brevo: Transactional → Settings → Webhook, URL `https://<host>/webhook/brevo`.
To require HTTP Basic auth, put `user:password@` in that URL and set
`BREVO_WEBHOOK_SECRET=user:password`.

- Brevo's source IPs (`1.179.112.0/20`, `172.246.240.0/20`) are enforced. In debug mode Symfony
  also allows `127.0.0.1`, which is what lets a dev app behind a cloudflared tunnel receive them.
  In prod the app must trust its reverse proxy (`trusted_proxies`) or every call is rejected (406).
- Webhooks go through Messenger when it is installed. Without a worker, use a sync transport
  for `Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage`.
- Only **transactional** webhook payloads are parsed. Marketing (campaign) webhooks use a
  different payload and are not handled yet.

## Configuration

```yaml
survos_brevo:
    api_key: '%env(BREVO_API_KEY)%'   # default; empty = not configured
    webhook: true                     # set false to leave /webhook/brevo alone
```
