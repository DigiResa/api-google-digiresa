<?php
namespace App\Service\Google;

use Doctrine\DBAL\Connection;

final class BookingService
{
    public function __construct(
        private Connection $db,
        private SlotEngine $slots
    ) {}

    /**
     * @param array{
     *   merchant_id:string,
     *   service_id?:string,
     *   start?:string,
     *   slots?:array<int,string>,
     *   party_size?:int,
     *   customer?:array{first_name?:string,last_name?:string,email?:string,phone?:string}
     * } $p
     * @return array{status:string, id?:string, start?:string, party?:int, code?:int, message?:string, replayed?:bool}
     */
    public function create(array $p, ?string $idempotencyKey = null): array
    {
        // 1) Merchant
        $rid = (int)$this->db->fetchOne('SELECT id FROM restaurant WHERE guid = ? LIMIT 1', [$p['merchant_id'] ?? '']);
        if (!$rid) {
            return ['status' => 'ERROR', 'code' => 404, 'message' => 'merchant not found'];
        }

        // 2) Datetime (accepte start OU slots[0])
        $startIso = $p['start'] ?? ($p['slots'][0] ?? null);
        if (!$startIso) {
            return ['status' => 'ERROR', 'code' => 400, 'message' => "missing 'start' or 'slots[0]'"];
        }
        try {
            $start = new \DateTimeImmutable($startIso);
        } catch (\Throwable) {
            return ['status' => 'ERROR', 'code' => 400, 'message' => 'invalid start datetime'];
        }

        // 3) Service / party
        $serviceId = $p['service_id'] ?? '';
        $party     = max(1, (int)($p['party_size'] ?? 2));

        // 4) Capacité
        $cap = $this->slots->capacityFor($rid, $serviceId, $start);
        if ($cap <= 0) {
            return ['status' => 'CONFLICT', 'code' => 409, 'message' => 'slot unavailable'];
        }
        if ($party > $cap) {
            return ['status' => 'CONFLICT', 'code' => 409, 'message' => 'party exceeds capacity'];
        }

        // 5) Client
        $first = trim($p['customer']['first_name'] ?? '');
        $last  = trim($p['customer']['last_name'] ?? '');
        $name  = trim($first . ' ' . $last) ?: 'Client Google';
        $email = $p['customer']['email'] ?? null;
        $phone = $this->normalizePhone($p['customer']['phone'] ?? null);

        // 6) Booking ID (idempotent si header fourni)
        $bookingId = null;
        if ($idempotencyKey) {
            $seed = implode('|', [
                $p['merchant_id'] ?? '',
                $serviceId,
                $start->format(DATE_ATOM),
                (string)$party,
                strtolower((string)$email),
                $idempotencyKey,
            ]);
            $bookingId = 'IDEMP_' . substr(hash('sha256', $seed), 0, 20);

            // si déjà existant -> renvoyer la même réponse (idempotent)
            $exists = (int)$this->db->fetchOne('SELECT COUNT(*) FROM booking WHERE guid = ?', [$bookingId]);
            if ($exists) {
                return [
                    'status'   => 'OK',
                    'id'       => $bookingId,
                    'start'    => $start->format(DATE_ISO8601),
                    'party'    => $party,
                    'replayed' => true,
                ];
            }
        }
        if (!$bookingId) {
            $bookingId = 'BK_' . $start->format('Ymd_Hi') . '_' . bin2hex(random_bytes(3));
        }

        // 7) INSERT auto-adaptatif
        try {
            $colsMeta = $this->db->fetchAllAssociative('SHOW COLUMNS FROM booking');
            $cols  = array_column($colsMeta, 'Field');
            $types = [];
            foreach ($colsMeta as $c) { $types[$c['Field']] = strtolower((string)($c['Type'] ?? '')); }

            $data = [
                'name'            => $name,
                'email'           => $email,
                'phone_number'    => $phone,
                'tableware_count' => $party,
                'date'            => $start->format('Y-m-d'),
                'hour'            => $start->format('H:i'),
                'restaurant_id'   => $rid,
                'guid'            => $bookingId,
            ];
            if (in_array('source', $cols, true))     { $data['source'] = 'google'; }
            if (in_array('status', $cols, true))     { $data['status'] = $data['status'] ?? 'CONFIRMED'; }
            if (in_array('created_at', $cols, true)) { $data['created_at'] = $data['created_at'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s'); }
            if (in_array('updated_at', $cols, true)) { $data['updated_at'] = $data['updated_at'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s'); }

            $fallbacksExplicit = [
                'sending_sms' => 0,
                'remind_sms'  => 0,
                'annulation'  => 0,
                'refuse'      => 0,
                'is_waiting'  => 0,
                'confirmed'   => 1,
            ];
            foreach ($colsMeta as $c) {
                $field   = $c['Field'];
                $null    = strtoupper((string)$c['Null']) === 'YES';
                $default = array_key_exists('Default', $c) ? $c['Default'] : null;
                $extra   = strtolower((string)$c['Extra']);
                $needsValue = !$null && $default === null && !str_contains($extra, 'auto_increment');

                if (!array_key_exists($field, $data) && $needsValue) {
                    if (array_key_exists($field, $fallbacksExplicit)) {
                        $data[$field] = $fallbacksExplicit[$field];
                        continue;
                    }
                    $t = $types[$field] ?? '';
                    if ($t !== '') {
                        if (str_starts_with($t, 'tinyint') || str_starts_with($t, 'smallint') || str_starts_with($t, 'int') || str_starts_with($t, 'bigint')) {
                            $data[$field] = 0;
                        } elseif (str_starts_with($t, 'decimal') || str_starts_with($t, 'float') || str_starts_with($t, 'double')) {
                            $data[$field] = 0;
                        } elseif (str_starts_with($t, 'time')) {
                            $data[$field] = $start->format('H:i:s');
                        } elseif (str_starts_with($t, 'date')) {
                            $data[$field] = $start->format('Y-m-d');
                        } elseif (str_starts_with($t, 'datetime') || str_starts_with($t, 'timestamp')) {
                            $data[$field] = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
                        } else {
                            $data[$field] = '';
                        }
                    }
                }
            }
            if (isset($types['hour']) && str_starts_with($types['hour'], 'time')) { $data['hour'] = $start->format('H:i:s'); }

            $data = array_intersect_key($data, array_flip($cols));

            $this->db->insert('booking', $data);
        } catch (\Throwable $e) {
            // En cas de collision unique (guid déjà présent), on renvoie l'idempotent OK
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'duplicate') !== false) {
                return [
                    'status'   => 'OK',
                    'id'       => $bookingId,
                    'start'    => $start->format(DATE_ISO8601),
                    'party'    => $party,
                    'replayed' => true,
                ];
            }
            return ['status' => 'ERROR', 'code' => 500, 'message' => 'db insert failed: ' . $msg];
        }

        return [
            'status' => 'OK',
            'id'     => $bookingId,
            'start'  => $start->format(DATE_ISO8601),
            'party'  => $party,
        ];
    }

 public function update(array $p, ?string $idempotencyKey = null): array
{
    // Payload attendu:
    // {
    //   "merchant_id": "...",
    //   "booking_id": "GUID (notre booking.guid)",
    //   "action": "CANCEL" | "MODIFY",
    //   "new_start": "2025-08-13T20:00:00+02:00",   // requis si MODIFY
    //   "new_party_size": 3                         // optionnel si MODIFY
    // }

    $rid = (int)$this->db->fetchOne('SELECT id FROM restaurant WHERE guid = ? LIMIT 1', [$p['merchant_id'] ?? '']);
    if (!$rid) return ['status'=>'ERROR','code'=>404,'message'=>'merchant not found'];

    $bkGuid = $p['booking_id'] ?? '';
    if (!$bkGuid) return ['status'=>'ERROR','code'=>400,'message'=>'missing booking_id'];

    $row = $this->db->fetchAssociative('SELECT * FROM booking WHERE guid=? AND restaurant_id=? LIMIT 1', [$bkGuid, $rid]);
    if (!$row) return ['status'=>'ERROR','code'=>404,'message'=>'booking not found'];

    $action = strtoupper((string)($p['action'] ?? ''));
    if (!in_array($action, ['CANCEL','MODIFY'], true)) {
        return ['status'=>'ERROR','code'=>400,'message'=>'invalid action'];
    }

    // Idempotency simple: si on a déjà un statut final identique, renvoyer OK
    if ($action === 'CANCEL') {
        // Marquer annulé + libérer capacité (ton SlotEngine peut ignorer une résa annulée)
        $this->db->update('booking', [
            // colonnes compatibles avec ton schéma:
            'status'      => in_array('status', array_column($this->db->fetchAllAssociative('SHOW COLUMNS FROM booking'), 'Field'), true) ? 'CANCELLED' : ($row['status'] ?? null),
            'annulation'  => array_key_exists('annulation', $row) ? 1 : ($row['annulation'] ?? null),
            'updated_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s'),
        ], ['guid' => $bkGuid]);

        return ['status'=>'OK','booking_id'=>$bkGuid,'result'=>'CANCELLED'];
    }

    // MODIFY
    $newStartIso = $p['new_start'] ?? null;
    if (!$newStartIso) return ['status'=>'ERROR','code'=>400,'message'=>'missing new_start'];
    try {
        $newStart = new \DateTimeImmutable($newStartIso);
    } catch (\Throwable) {
        return ['status'=>'ERROR','code'=>400,'message'=>'invalid new_start'];
    }

    $newParty = isset($p['new_party_size']) ? max(1, (int)$p['new_party_size']) : (int)$row['tableware_count'];

    // Vérifier capacité du nouveau créneau
    $serviceId = ''; // si tu stockes le service, récupère-le (ex: row['service_id'] ou dérive jour/noon/evening)
    $cap = $this->slots->capacityFor($rid, $serviceId, $newStart);
    if ($cap <= 0 || $newParty > $cap) {
        return ['status'=>'ERROR','code'=>409,'message'=>'new slot unavailable'];
    }

    // Appliquer la modif
    // Attention au format de 'hour' (TIME vs varchar); adapte si besoin (H:i:s)
    $this->db->update('booking', [
        'date'            => $newStart->format('Y-m-d'),
        'hour'            => $newStart->format('H:i'),
        'tableware_count' => $newParty,
        'updated_at'      => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s'),
    ], ['guid' => $bkGuid]);

    return [
        'status'     => 'OK',
        'booking_id' => $bkGuid,
        'start'      => $newStart->format(DATE_ISO8601),
        'party'      => $newParty,
        'result'     => 'MODIFIED'
    ];
}


    private function normalizePhone(?string $raw): ?string
    {
        if (!$raw) return null;
        $d = preg_replace('/\D+/', '', $raw);
        if (!$d) return null;
        if (strlen($d) === 10 && str_starts_with($d, '0')) { return '+33' . substr($d, 1); }
        if (str_starts_with($raw, '+')) { return '+' . preg_replace('/\D+/', '', substr($raw, 1)); }
        return $d;
    }
}
