#!/bin/sh
# Seeds a tenant and a demo form (one field of each type + a visibility chain), publishes it, prints the public URL
# and the API key. Needs `make up` to have run. Everything goes through the real API.
set -eu
cd "$(dirname "$0")/.."

API=${API:-http://localhost:8000}
PUBLIC=${PUBLIC:-http://localhost:8080}

KEY=$(docker compose run --rm --no-deps -T migrate php artisan tenants:create "Demo tenant" | sed 's/\x1b\[[0-9;]*m//g' | awk '/api_key:/ {print $2}')

FORM=$(curl -sf "$API/v1/forms" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d @- <<'JSON' | sed -E 's/^\{"id":"([^"]+)".*/\1/'
{"name": "Demo: every field type", "definition": {"fields": [
  {"id": "name",     "type": "text",        "label": "Name",            "required": true,  "rules": {"min_length": 2, "max_length": 80}},
  {"id": "email",    "type": "email",       "label": "Email",           "required": true,  "help_text": "We reply here"},
  {"id": "guests",   "type": "number",      "label": "Number of guests","required": true,  "rules": {"min": 0, "max": 10, "integer": true}},
  {"id": "plan",     "type": "select",      "label": "Plan",            "required": true,  "options": [{"value": "free", "label": "Free"}, {"value": "pro", "label": "Pro"}]},
  {"id": "addons",   "type": "multiselect", "label": "Add-ons",         "required": false, "options": [{"value": "a", "label": "Analytics"}, {"value": "b", "label": "Backups"}, {"value": "c", "label": "Custom domain"}], "rules": {"max_selected": 2},
                     "visible_if": {"field": "plan", "op": "eq", "value": "pro"}},
  {"id": "billing",  "type": "radio",       "label": "Billing period",  "required": true,  "options": [{"value": "monthly", "label": "Monthly"}, {"value": "yearly", "label": "Yearly"}],
                     "visible_if": {"field": "addons", "op": "in", "value": ["a", "b"]}},
  {"id": "start",    "type": "date",        "label": "Start date",      "required": false, "rules": {"min": "2026-01-01"}},
  {"id": "consent",  "type": "checkbox",    "label": "I agree to the terms", "required": true}
]}}
JSON
)

curl -sf -X POST "$API/v1/forms/$FORM/publish" -H "Authorization: Bearer $KEY" >/dev/null

echo "api key:   $KEY"
echo "form id:   $FORM"
echo "form page: $PUBLIC/f/$FORM"
echo "version:   $PUBLIC/v1/forms/$FORM"
