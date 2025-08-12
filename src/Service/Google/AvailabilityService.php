<?php
declare(strict_types=1);

namespace App\Service\Google;

use Doctrine\DBAL\Connection;

final class AvailabilityService
{
    public function __construct(
        private Connection $db,
        private SlotEngine $slots
    ) {}

    /**
     * @param string   $merchantGuid  GUID du restaurant (restaurant.guid)
     * @param string   $serviceId     format attendu: "{GUID}:noon" ou "{GUID}:evening"
     * @param string[] $starts        ISO-8601 (avec offset), ex: "2025-08-12T12:00:00+02:00"
     * @param int      $party         taille du groupe (pour future logique de filtrage)
     * @return array<int,array{start:string,capacity:int}>
     */
    public function lookup(string $merchantGuid, string $serviceId, array $starts, int $party): array
    {
        // 0) Normalisations rapides
        $merchantGuid = trim($merchantGuid);
        $serviceId    = trim($serviceId);
        $starts       = array_values(array_filter($starts, fn($s) => is_string($s) && $s !== ''));

        if ($merchantGuid === '' || $serviceId === '' || $starts === []) {
            // Pas d’inputs valides ⇒ renvoyer des slots vides (capacity 0)
            return array_map(fn($s) => ['start' => $s, 'capacity' => 0], $starts);
        }

        // 1) Résoudre restaurant_id
        $rid = (int) $this->db->fetchOne(
            "SELECT id FROM restaurant WHERE guid = ?",
            [$merchantGuid]
        );
        if ($rid <= 0) {
            return array_map(fn($s) => ['start' => $s, 'capacity' => 0], $starts);
        }

        // 2) Vérifier que serviceId matche bien ce resto (sécurité légère)
        //    format attendu: "{GUID}:noon" ou "{GUID}:evening"
        $suffix = strtolower(substr($serviceId, strrpos($serviceId, ':') + 1));
        if (!in_array($suffix, ['noon', 'evening'], true)) {
            return array_map(fn($s) => ['start' => $s, 'capacity' => 0], $starts);
        }
        $prefix = substr($serviceId, 0, strrpos($serviceId, ':'));
        if ($prefix !== $merchantGuid) {
            // service d’un autre merchant ⇒ 0 partout
            return array_map(fn($s) => ['start' => $s, 'capacity' => 0], $starts);
        }

        // 3) Parser les instants en conservant l’offset fourni (par défaut Paris)
        $tzParis = new \DateTimeZone('Europe/Paris');
        $parsed  = [];
        foreach ($starts as $iso) {
            try {
                // On respecte l’offset si présent dans l’ISO ; sinon fallback Paris.
                $dt = new \DateTimeImmutable($iso);
            } catch (\Throwable) {
                // ISO invalide ⇒ capacity=0
                $parsed[] = ['iso' => $iso, 'dt' => null];
                continue;
            }
            // Si l’ISO est “naïf” (sans tz), on force Paris
            if ($dt->getTimezone()->getName() === '+00:00' && !str_contains($iso, '+') && !str_contains($iso, 'Z')) {
                $dt = new \DateTimeImmutable($dt->format('Y-m-d H:i:s'), $tzParis);
            }
            $parsed[] = ['iso' => $iso, 'dt' => $dt];
        }

        // 4) Calculer la capacité restante
        $out = [];
        foreach ($parsed as $row) {
            $iso = $row['iso'];
            $dt  = $row['dt'];

            if (!$dt) {
                $out[] = ['start' => $iso, 'capacity' => 0];
                continue;
            }

            // (Option) Ici tu peux arrondir $dt au booking_step si nécessaire.
            // $dt = $this->roundToStep($rid, $dt);

            $cap = $this->slots->capacityFor($rid, $serviceId, $dt);
            // (Option) Filtrer par party_size max si tu as cette logique
            $out[] = ['start' => $iso, 'capacity' => max(0, (int)$cap)];
        }

        // 5) Trier par datetime pour une réponse stable
        usort($out, function ($a, $b) {
            return strcmp($a['start'], $b['start']);
        });

        return $out;
    }

    /**
     * Exemple d’arrondi au pas (si tu veux l’activer).
     */
    private function roundToStep(int $restaurantId, \DateTimeImmutable $dt): \DateTimeImmutable
    {
        $cfg = $this->db->fetchAssociative(
            "SELECT booking_step FROM restaurant_config WHERE restaurant_id = ?",
            [$restaurantId]
        );
        $step = max(1, (int)($cfg['booking_step'] ?? 15));

        $minutes = (int)$dt->format('i');
        $delta   = $minutes % $step;
        $new     = $dt->modify(sprintf('-%d minutes', $delta));

        // Si tu préfères arrondir vers le haut :
        // $delta = ($step - ($minutes % $step)) % $step;
        // $new   = $dt->modify(sprintf('+%d minutes', $delta));

        return $new;
    }
}
