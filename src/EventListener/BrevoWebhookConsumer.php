<?php

declare(strict_types=1);

namespace Survos\BrevoBundle\EventListener;

use Psr\Log\LoggerInterface;
use Survos\BrevoBundle\Event\BrevoMailEvent;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsRemoteEventConsumer('brevo')]
final class BrevoWebhookConsumer implements ConsumerInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        if (!$event instanceof AbstractMailerEvent) {
            $this->logger?->warning('Brevo webhook: unexpected event {class}', ['class' => $event::class]);

            return;
        }
        $mailEvent = new BrevoMailEvent($event);
        $this->logger?->info('Brevo webhook: {name} for {email}', ['name' => $mailEvent->name, 'email' => $mailEvent->email]);
        $this->dispatcher->dispatch($mailEvent);
    }
}
