# STS2 Slack Bot

Self-hosted Slack bot for looking up Slay the Spire 2 cards, relics, potions,
enemies, events, and enchants via a `/sts` slash command. Laravel 12 + SQLite,
single workspace, no auth beyond Slack request signing.

## Setup

    composer install
    cp .env.example .env
    php artisan key:generate
    touch database/database.sqlite
    php artisan migrate
    php artisan sts:import          # loads the committed fixture data
    php artisan storage:link
    php artisan sts:images          # downloads entity art for Slack cards

Set `SLACK_SIGNING_SECRET` in `.env` (Slack app → Basic Information →
Signing Secret). `APP_URL` must be the public HTTPS host — Slack loads entity
images from `{APP_URL}/storage/...`.

## Slack app configuration

Expose the app over HTTPS (reverse proxy, Cloudflare Tunnel, ngrok, …), then
in your Slack app config:

| Setting | Value |
|---|---|
| Slash command `/sts` → Request URL | `https://your-host/slack/command` |
| Interactivity → Request URL | `https://your-host/slack/interactions` |
| Interactivity → Options Load URL | `https://your-host/slack/options` |

Usage: `/sts` opens a live-filtering search menu; `/sts <name>` answers
directly (with did-you-mean buttons when ambiguous).

## Importing data

    php artisan sts:import                                  # fixture data (default)
    php artisan sts:import --provider=untapped              # rescrape sts2.untapped.gg
    php artisan sts:import --provider=untapped --save-fixtures  # rescrape + refresh fixtures

Providers implement `App\Import\ImportProvider` and are registered in
`config/sts.php`. Enemies and enchants have no source on untapped.gg yet; the
schema supports them, so a future provider (or hand-written fixture file in
`database/data/`) can add them.

`STS_CARD_IMAGE_VARIANT=portrait|preview` picks which card image Slack shows
(portrait art vs the full rendered card frame).

## Tests

    ./vendor/bin/pest
