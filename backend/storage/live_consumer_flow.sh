#!/bin/bash
# Live consumer flow (§24): login -> browse -> search -> nearby -> view -> like ->
# comment -> reply-as-seller check -> report -> withdraw -> purchase refused.
# Consumer account flutter.live.check@hassal.test has NO organization by design.
B=http://127.0.0.1:8000/api/v1
OUT=D:/hassal/backend/storage/live_consumer_flow.txt
: > "$OUT"
say() { echo "$1" | tee -a "$OUT"; }

say "== register/login (consumer, no org) =="
EMAIL="live.consumer.$(date +%s)@hassal.test"
NSUX=$(( $(od -An -N4 -tu4 /dev/urandom | tr -d ' \r\n') % 1000000000 ))
[ ${#NSUX} -lt 8 ] && NSUX="${NSUX}7$(date +%S)"
LOGIN=$(curl -s -X POST "$B/auth/register" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"name\":\"مستهلك فحص حي\",\"email\":\"$EMAIL\",\"password\":\"Live#Check#2026#x\",\"password_confirmation\":\"Live#Check#2026#x\",\"phone\":\"+9677${NSUX:0:9}\",\"national_id\":\"${NSUX:0:8}\"}")
echo "$LOGIN" | head -c 400 >> "$OUT"; echo >> "$OUT"
TOK=$(echo "$LOGIN" | tr -d '\r' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p' | head -1)
say "token length: ${#TOK}"
say "== re-login with the same credentials (proves the row signed in, not just registered) =="
LOGIN2=$(curl -s -X POST "$B/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"login\":\"$EMAIL\",\"password\":\"Live#Check#2026#x\"}")
TOK=$(echo "$LOGIN2" | tr -d '\r' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p' | head -1)
say "login token length: ${#TOK}"
AUTH=(-H "Authorization: Bearer $TOK" -H 'Accept: application/json')

say "== browse offers =="
curl -s "${AUTH[@]}" "$B/marketplace/offers" | head -c 300 >> "$OUT"; echo >> "$OUT"

say "== search =="
curl -s "${AUTH[@]}" "$B/marketplace/offers?search=%D8%A3%D8%B1%D8%B2" | head -c 220 >> "$OUT"; echo >> "$OUT"

say "== nearby =="
curl -s "${AUTH[@]}" "$B/marketplace/offers/nearby?latitude=12.78&longitude=45.02&radius_km=50" | head -c 220 >> "$OUT"; echo >> "$OUT"

say "== pick first active offer id =="
OFFER=$(curl -s "${AUTH[@]}" "$B/marketplace/offers" | tr -d '\r' | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
say "offer id: $OFFER"

say "== view offer detail =="
curl -s "${AUTH[@]}" "$B/marketplace/offers/$OFFER" | head -c 220 >> "$OUT"; echo >> "$OUT"

say "== like (as person, no tenant header) =="
curl -s -X POST "${AUTH[@]}" -H 'Content-Type: application/json' "$B/marketplace/offers/$OFFER/like" -d '{}' | tee -a "$OUT"; echo >> "$OUT"

say "== comment =="
C=$(curl -s -X POST "${AUTH[@]}" -H 'Content-Type: application/json' "$B/marketplace/offers/$OFFER/comments" \
  -d '{"body":"فحص حي: هل التسليم متاح غدًا؟"}')
echo "$C" | tee -a "$OUT"; echo >> "$OUT"
CID=$(echo "$C" | tr -d '\r' | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
say "comment id: $CID"

say "== thread shows consumer row without org =="
curl -s "${AUTH[@]}" "$B/marketplace/offers/$OFFER/comments" | head -c 500 >> "$OUT"; echo >> "$OUT"

say "== report =="
curl -s -X POST "${AUTH[@]}" -H 'Content-Type: application/json' "$B/marketplace/comments/$CID/report" \
  -d '{"reason":"other","note":"فحص بلاغ حي"}' | tee -a "$OUT"; echo >> "$OUT"

say "== purchase request must be refused (no org) =="
curl -s -X POST "${AUTH[@]}" -H 'Content-Type: application/json' "$B/purchase-requests" \
  -d "{\"offer_id\":$OFFER,\"quantity\":5}" | head -c 300 | tee -a "$OUT"; echo >> "$OUT"

say "== liked list is per person =="
curl -s "${AUTH[@]}" "$B/marketplace/liked-offers" | head -c 200 | tee -a "$OUT"; echo >> "$OUT"

say "== withdraw own comment =="
curl -s -X DELETE "${AUTH[@]}" "$B/marketplace/comments/$CID" | head -c 220 | tee -a "$OUT"; echo >> "$OUT"

say "== unlike =="
curl -s -X DELETE "${AUTH[@]}" "$B/marketplace/offers/$OFFER/like" | head -c 220 | tee -a "$OUT"; echo >> "$OUT"

say "== notifications inbox (person capability, no org) =="
curl -s -o /dev/null -w "GET /notifications -> %{http_code}\n" "${AUTH[@]}" "$B/notifications" | tee -a "$OUT"
curl -s "${AUTH[@]}" "$B/notifications/unread-count" | head -c 200 | tee -a "$OUT"; echo >> "$OUT"

say DONE
