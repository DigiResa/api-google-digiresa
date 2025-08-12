<?php
namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GoogleApiSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $hmacSecret,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $req = $event->getRequest();
        $path = $req->getPathInfo();

        // Protège uniquement les endpoints Google (sauf health)
        if (!str_starts_with($path, '/google') || $path === '/google/health') return;

        $key = $req->headers->get('X-Api-Key') ?? '';
        if (!$this->apiKey || !hash_equals($this->apiKey, $key)) {
            throw new AccessDeniedHttpException('Invalid API key');
        }

        // HMAC optionnel
        if ($this->hmacSecret) {
            $sig  = $req->headers->get('X-Signature') ?? ''; // "sha256=..."
            $calc = 'sha256=' . hash_hmac('sha256', $req->getContent(), $this->hmacSecret);
            if (!hash_equals($calc, $sig)) {
                throw new AccessDeniedHttpException('Invalid signature');
            }
        }
    }
}
