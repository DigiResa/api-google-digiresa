# api-google-digiresa



# API Google DigiResa

API Symfony 6/7 exposant:
- `/google/health`
- `/google/availability-lookup` (POST)
- `/google/create-booking` (POST, idempotent via `X-Idempotency-Key`)
- `/google/feed/*` (merchant/services/availability)

## Démarrage
```bash
composer install
cp .env.example .env.local  # Renseigner DB + clés
symfony server:start -d
5) (optionnel) Dossier docs + curls
```bash
mkdir -p docs/curl
cat > docs/curl/create-booking.sh <<'EOF'
GUID="cc289def-66cb-4a29-af29-4846e0be9737"
BODY='{
  "merchant_id":"'"$GUID"'",
  "service_id":"'"$GUID"':evening",
  "start":"2025-08-13T19:30:00+02:00",
  "party_size":2,
  "customer":{"first_name":"Ana","last_name":"Silva","phone":"+33612345678","email":"ana@example.com"}
}'
curl -i -X POST http://127.0.0.1:8000/google/create-booking \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: change-me-very-strong" \
  -H "X-Idempotency-Key: test-123" \
  -d "$BODY"
EOF
chmod +x docs/curl/create-booking.sh
