<?php
namespace App\Service\Google;

use Doctrine\DBAL\Connection;

final class SlotEngine
{
    public function __construct(private Connection $db) {}

    /** Capacité restante pour un slot (V1 “quotas par créneau”). */
    public function capacityFor(int $restaurantId, string $serviceId, \DateTimeImmutable $start): int
    {
        $cfg = $this->db->fetchAssociative("
            SELECT booking_step, max_booking_by_step, booking_step_table_count,
                   today_booking_noon_max_hour, today_booking_evening_max_hour
            FROM restaurant_config WHERE restaurant_id = ?
        ", [$restaurantId]);

        $step = (int)($cfg['booking_step'] ?? 15);
        $cap  = (int)($cfg['max_booking_by_step'] ?? $cfg['booking_step_table_count'] ?? 6);

        // Cutoff jour J
        $period = $this->periodOf($serviceId); // noon|evening
        $cutoff = $period === 'noon' ? ($cfg['today_booking_noon_max_hour'] ?? null)
                                     : ($cfg['today_booking_evening_max_hour'] ?? null);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        if ($start->format('Y-m-d') === $now->format('Y-m-d') && $this->isHhmm($cutoff)) {
            if ($start->format('H:i') >= $cutoff) return 0;
        }

        // Occupation (réservations non annulées/refusées alignées sur le pas)
        $count = (int)$this->db->fetchOne("
            SELECT COUNT(*) FROM booking
            WHERE restaurant_id = ? AND date = ? AND hour = ?
              AND (annulation IS NULL OR annulation = 0)
              AND (refuse IS NULL OR refuse = 0)
        ", [$restaurantId, $start->format('Y-m-d'), $start->format('H:i')]);

        return max(0, $cap - $count);
    }

    private function periodOf(string $serviceId): string
    {
        $p = strtolower(substr($serviceId, strrpos($serviceId, ':') + 1));
        return in_array($p, ['noon','evening'], true) ? $p : 'noon';
    }

    private function isHhmm(?string $v): bool
    {
        return $v !== null && preg_match('/^\d{2}:\d{2}$/', $v) === 1;
    }
}
