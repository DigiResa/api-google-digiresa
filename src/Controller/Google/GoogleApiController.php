<?php
namespace App\Controller\Google;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\Google\AvailabilityService;
use App\Service\Google\BookingService;
use App\Service\Google\MerchantFeed;
use App\Service\Google\ServicesFeed;
use App\Service\Google\AvailabilityFeed;

#[Route('/google')]
final class GoogleApiController
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingService $booking,
    ) {}

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format(DATE_ISO8601),
        ]);
    }

    #[Route('/availability-lookup', methods: ['POST'])]
    public function availability(Request $r): JsonResponse
    {
        $p = json_decode($r->getContent(), true) ?? [];
        $res = $this->availability->lookup(
            $p['merchant_id'] ?? '',
            $p['service_id'] ?? '',
            $p['slots'] ?? [],
            (int)($p['party_size'] ?? 2)
        );
        return new JsonResponse(['results' => $res]);
    }

    #[Route('/create-booking', methods: ['POST'])]
    public function create(Request $r): JsonResponse
    {
        $p = json_decode($r->getContent(), true) ?? [];
        $idempo = $r->headers->get('X-Idempotency-Key');

        $res = $this->booking->create($p, $idempo);

        if (($res['status'] ?? '') === 'CONFLICT') {
            return new JsonResponse(['error' => 'UNAVAILABLE', 'message' => $res['message']], 409);
        }
        if (($res['status'] ?? '') === 'ERROR') {
            return new JsonResponse(['error' => 'ERROR', 'message' => $res['message']], $res['code'] ?? 400);
        }

        $code = !empty($res['replayed']) ? 200 : 201;
        return new JsonResponse([
            'booking_id' => $res['id'],
            'status'     => 'CONFIRMED',
            'start'      => $res['start'],
            'party_size' => $res['party']
        ], $code);
    }

    #[Route('/update-booking', methods: ['POST'])]
    public function update(Request $r): JsonResponse
    {
        $p = json_decode($r->getContent(), true) ?? [];
        // Ajoute l’idempotency ici si/quad tu l’implémentes dans BookingService::update(...)
        $res = $this->booking->update($p);

        if (($res['status'] ?? '') === 'ERROR') {
            return new JsonResponse(['error' => 'ERROR', 'message' => $res['message']], $res['code'] ?? 400);
        }
        return new JsonResponse($res, 200);
    }

    // --- FEEDS ---

    #[Route('/feed/merchant/{guid}', methods: ['GET'])]
    public function merchant(string $guid, MerchantFeed $svc): JsonResponse
    {
        $data = $svc->one($guid);
        if (!$data) {
            return new JsonResponse(['error' => 'not found'], 404);
        }
        return new JsonResponse(['merchant' => $data]);
    }

    #[Route('/feed/services/{guid}', methods: ['GET'])]
    public function services(string $guid, ServicesFeed $svc): JsonResponse
    {
        $rows = $svc->list($guid);
        return new JsonResponse(['services' => $rows ?: []]);
    }

    #[Route('/feed/availability/{guid}', methods: ['GET'])]
    public function availabilityFeed(Request $r, string $guid, AvailabilityFeed $svc): JsonResponse
    {
        $start = $r->query->get('start'); // YYYY-MM-DD
        $end   = $r->query->get('end');   // YYYY-MM-DD
        $res = $svc->range($guid, $start, $end);
        return new JsonResponse($res ?: ['availability' => [], 'range' => ['start' => $start, 'end' => $end]]);
    }
}
