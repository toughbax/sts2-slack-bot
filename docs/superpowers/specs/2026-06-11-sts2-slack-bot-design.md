# STS2 Slack Bot — Design

**Date:** 2026-06-11
**Status:** Approved

## Overview

A self-hosted Laravel 12 application backing a single Slack slash command, `/sts`,
that lets one Slack workspace look up Slay the Spire 2 entities (cards, relics,
potions, enemies, events, enchants) by fuzzy name search. SQLite storage, no
authentication beyond Slack request signing, no admin UI, no frontend, no user
accounts.

## Stack

- Laravel 12, PHP 8.4
- SQLite (single self-hosted instance)
- Pest for tests

## User experience

Slack does not support live autocomplete on slash-command arguments, so two
entry paths are provided:

1. **`/sts`** (no arguments) — responds with an ephemeral message containing an
   external-select menu ("Search cards, relics, potions…"). As the user types
   in the select, Slack calls the options endpoint and the option list narrows
   live (fuzzy matched). Picking an option replaces the message with the full
   entity card.
2. **`/sts <query>`** — fuzzy-searches immediately. The top match renders in
   full; if other close matches exist, a "did you mean" set of buttons lists
   them. Pressing one swaps in that entity's card.

## Domain model

Single `entities` table:

| Column | Notes |
|---|---|
| `id` | PK |
| `type` | enum: `card`, `relic`, `potion`, `enemy`, `event`, `enchant` |
| `name` | display name |
| `slug` | normalized name |
| `description` | mechanics/effect text |
| `source_url` | nullable; link to the entity's page on the source site |
| `metadata` | JSON; type-specific extras (cost, rarity, character, …) |
| `provider` | which import provider produced the row |
| timestamps | |

Unique index on `(type, slug)` so identically named entities of different types
coexist. One `Entity` Eloquent model with a `PHP backed enum` for `type`. No
per-type tables: the types share a shape, and one table keeps search trivial.

## HTTP layer

Three POST routes, all behind `VerifySlackSignature` middleware (HMAC check of
Slack's signing secret + timestamp staleness rejection — Slack hygiene, not
user auth):

| Route | Purpose |
|---|---|
| `POST /slack/command` | `/sts` handler. Empty text → select prompt. Text → top match + did-you-mean. |
| `POST /slack/options` | External-select options load. Returns up to 25 fuzzy-ranked options, labeled "Name · Type". |
| `POST /slack/interactions` | Block actions payload (select choice or did-you-mean button) → responds via `response_url`, replacing the message with the entity card. |

All responses are ephemeral Block Kit payloads.

## Search

`EntitySearchService`:

1. Normalize the query (lowercase, trim, collapse whitespace).
2. Rank candidates: exact name > prefix > substring > trigram/levenshtein
   similarity above a threshold (typo tolerance, e.g. "ardstone" →
   "Hardstone").
3. Entity names load once per request from SQLite and are scored in PHP —
   milliseconds at a few-thousand-entity scale.

Returns a ranked result list; callers decide presentation (full card, options
list, did-you-mean).

## Block Kit rendering

`BlockKitFormatter` — pure functions from domain objects to Block Kit arrays:

- **Entity card:** header (name), context line (type · rarity/character when
  present in metadata), description section, source-URL link.
- **Select prompt:** message containing the external-select element.
- **Did-you-mean:** the top match's card plus buttons for other close matches.

## Import

`ImportProvider` interface:

```php
interface ImportProvider
{
    public function name(): string;

    /** @return iterable<ImportedEntity> */
    public function entities(): iterable;
}
```

`ImportedEntity` is a readonly DTO (type, name, description, sourceUrl,
metadata).

Implementations:

- **`FixtureProvider` (default)** — reads committed `database/data/*.json`
  files. Deterministic, network-free; the shipped seed data.
- **`UntappedProvider`** — manually-run scrape of <https://sts2.untapped.gg>
  (robots.txt permits; data is client-rendered, so implementation starts with
  a spike to capture the site's JSON feed). Its output can be re-saved as
  updated fixtures. If no stable JSON feed is found during the spike, this
  provider ships as a documented stub and the fixture data is produced by a
  one-off dev scrape instead.

`php artisan sts:import [--provider=name]` resolves the provider from
`config/sts.php` (providers map + default), upserts by `(type, slug)`, and
reports created/updated counts.

## Configuration

- `config/sts.php` — provider class map, default provider name.
- `config/services.php` — `slack.signing_secret` from env.

## Testing (Pest)

- Feature tests for all three endpoints using correctly signed fake requests.
- Unit tests for search ranking, including typo cases.
- Formatter snapshot tests (entity card, select prompt, did-you-mean).
- Import command tests with an in-memory fake provider (created/updated paths).
- `FixtureProvider` test against a small test fixture file.
- Middleware rejection tests: bad signature, stale timestamp, missing headers.

## Out of scope

- User accounts, admin UI, public frontend, OAuth/Slack app distribution.
- Scheduled/automatic re-imports (imports are manual by design).
- Multi-workspace support.
