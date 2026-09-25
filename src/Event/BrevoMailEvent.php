<?php

declare(strict_types=1);

namespace Survos\BrevoBundle\Event;

use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent as RemoteMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;

/**
 * A Brevo webhook call, parsed by symfony/brevo-mailer and re-dispatched so any number of
 * listeners (outreach-bundle, the app) can react. A RemoteEvent name has exactly one consumer;
 * this bundle is it.
 */
final class BrevoMailEvent
{
    public function __construct(
        public readonly RemoteMailerEvent $remoteEvent,
    ) {
    }

    /** e.g. MailerDeliveryEvent::BOUNCE, MailerEngagementEvent::UNSUBSCRIBE */
    public string $name {
        get => $this->remoteEvent->getName();
    }

    public string $email {
        get => strtolower($this->remoteEvent->getRecipientEmail());
    }

    /** Brevo's message id: what the API transport returned when the message was sent. */
    public string $messageId {
        get => $this->remoteEvent->getId();
    }

    /** Stops future mail: hard bounce, spam complaint, unsubscribe. Opens and clicks do not. */
    public bool $isSuppression {
        get => match (true) {
            $this->remoteEvent instanceof MailerEngagementEvent => \in_array($this->name, [MailerEngagementEvent::UNSUBSCRIBE, MailerEngagementEvent::SPAM], true),
            $this->remoteEvent instanceof MailerDeliveryEvent => $this->name === MailerDeliveryEvent::BOUNCE
                && ($this->remoteEvent->getPayload()['event'] ?? null) === 'hard_bounce',
            default => false,
        };
    }
}
