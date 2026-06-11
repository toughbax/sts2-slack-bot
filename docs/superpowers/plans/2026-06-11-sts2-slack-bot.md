# STS2 Slack Bot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A self-hosted Laravel 12 app backing a `/sts` Slack slash command that fuzzy-searches Slay the Spire 2 entities (cards, relics, potions, enemies, events, enchants) stored in SQLite, with a provider-based importer (`sts:import`).

**Architecture:** Three signature-verified Slack POST endpoints (command, options-load, interactions) share an `EntitySearchService` (trigram fuzzy ranking in PHP) and a `BlockKitFormatter` (pure array builders). Imports go through an `ImportProvider` interface with two implementations: `FixtureProvider` (committed JSON, default) and `UntappedProvider` (manual scrape of sts2.untapped.gg via its sitemaps + meta descriptions).

**Tech Stack:** Laravel 12, PHP 8.4, SQLite, Pest 3, Laravel HTTP client.

**Spec:** `docs/superpowers/specs/2026-06-11-sts2-slack-bot-design.md`

**Spike findings (already verified against the live site):**
- `https://sts2.untapped.gg/sitemap/{cards,relics,potions,events}.xml` enumerate every entity detail URL (`/en/{section}/{slug}`). Listing pages paginate client-side; sitemaps are the reliable index. robots.txt allows scraping.
- Card/relic/potion detail pages carry a static parseable meta tag, e.g. `<meta name="description" content="Ball Lightning is a 1-Cost Common Attack card in the Defect pool: Deal 7 damage. Channel 1 Lightning.">`, `"Akabeko is a Uncommon relic in the Colorless pool: …"`, `"Ashwater is a Uncommon potion in the Ironclad pool: …"`.
- Event pages (and a few cards) have **no static meta tag**; the same content is in the Next.js flight payload as `\"name\":\"description\",\"content\":\"…\"` (JSON-escaped). Extraction must try the static tag first, then the flight payload.
- `<title>` gives the name: `Ball Lightning – Defect Common Attack – …` / `Abyssal Baths (Event) – …`.
- Enemies/enchants don't exist on untapped.gg yet. Schema supports them; untapped imports only cards/relics/potions/events.

---

### Task 1: Scaffold Laravel 12 with Pest and SQLite

**Files:**
- Create: entire Laravel skeleton at repo root (via composer create-project into temp dir, then rsync)
- Modify: `phpunit.xml`, `tests/Pest.php`, `.env.example`
- Delete: `.gitkeep`, `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`

- [ ] **Step 1: Create the skeleton**

```bash
composer create-project laravel/laravel:^12.0 /tmp/sts2-app --no-interaction
rsync -a --exclude=.git /tmp/sts2-app/ /Users/andrew/conductor/workspaces/sts2-slack-bot/budapest/
rm -rf /tmp/sts2-app
rm /Users/andrew/conductor/workspaces/sts2-slack-bot/budapest/.gitkeep
```

Expected: `artisan`, `composer.json`, `app/`, `.env` exist at repo root. The skeleton defaults to SQLite (`DB_CONNECTION=sqlite`, `database/database.sqlite`) — verify `.env` says so; no change needed.

- [ ] **Step 2: Install Pest**

```bash
cd /Users/andrew/conductor/workspaces/sts2-slack-bot/budapest
composer remove phpunit/phpunit --dev --no-update
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
rm tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php
```

- [ ] **Step 3: Write `tests/Pest.php`** (overwrite whatever exists)

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(RefreshDatabase::class)->in('Feature');
```

- [ ] **Step 4: Add test env vars to `phpunit.xml`**

Inside the existing `<php>` block add:

```xml
<env name="SLACK_SIGNING_SECRET" value="testing-secret"/>
<env name="STS_UNTAPPED_DELAY_MS" value="0"/>
```

(`DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` are already there in the skeleton — verify, don't duplicate.)

- [ ] **Step 5: Add Slack env keys to `.env.example` and `.env`**

Append to both files:

```dotenv
SLACK_SIGNING_SECRET=
```

- [ ] **Step 6: Write a smoke test** — `tests/Feature/SmokeTest.php`

```php
<?php

it('boots the application', function () {
    $this->get('/')->assertSuccessful();
});
```

- [ ] **Step 7: Run the suite**

Run: `./vendor/bin/pest`
Expected: PASS (1 test).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel 12 with Pest and SQLite"
```

---

### Task 2: EntityType enum, Entity model, migration, factory

**Files:**
- Create: `app/Enums/EntityType.php`
- Create: `app/Models/Entity.php`
- Create: `database/migrations/0001_01_01_000003_create_entities_table.php` (use `php artisan make:migration create_entities_table` for the real timestamp)
- Create: `database/factories/EntityFactory.php`
- Test: `tests/Feature/EntityModelTest.php`

- [ ] **Step 1: Write the failing test** — `tests/Feature/EntityModelTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Database\UniqueConstraintViolationException;

it('stores an entity with casts', function () {
    $entity = Entity::create([
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning',
        'description' => 'Deal 7 damage. Channel 1 Lightning.',
        'source_url' => 'https://sts2.untapped.gg/en/cards/ball-lightning',
        'metadata' => ['cost' => '1', 'rarity' => 'Common'],
        'provider' => 'untapped',
    ]);

    expect($entity->refresh())
        ->type->toBe(EntityType::Card)
        ->metadata->toBe(['cost' => '1', 'rarity' => 'Common']);
});

it('allows the same slug across types but not within one', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'slug' => 'anchor']);
    Entity::factory()->create(['type' => EntityType::Relic, 'slug' => 'anchor']);

    expect(Entity::count())->toBe(2);

    Entity::factory()->create(['type' => EntityType::Card, 'slug' => 'anchor']);
})->throws(UniqueConstraintViolationException::class);

it('has a label for every type', function () {
    expect(EntityType::Card->label())->toBe('Card')
        ->and(EntityType::Enchant->label())->toBe('Enchant');
});
```

- [ ] **Step 2: Run it** — `./vendor/bin/pest tests/Feature/EntityModelTest.php` — Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`app/Enums/EntityType.php`:

```php
<?php

namespace App\Enums;

enum EntityType: string
{
    case Card = 'card';
    case Relic = 'relic';
    case Potion = 'potion';
    case Enemy = 'enemy';
    case Event = 'event';
    case Enchant = 'enchant';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
```

`app/Models/Entity.php`:

```php
<?php

namespace App\Models;

use App\Enums\EntityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entity extends Model
{
    /** @use HasFactory<\Database\Factories\EntityFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'slug',
        'description',
        'source_url',
        'metadata',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'type' => EntityType::class,
            'metadata' => 'array',
        ];
    }
}
```

Migration (`php artisan make:migration create_entities_table`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->string('slug');
            $table->text('description');
            $table->string('source_url')->nullable();
            $table->json('metadata');
            $table->string('provider');
            $table->timestamps();

            $table->unique(['type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
```

`database/factories/EntityFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\EntityType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Entity>
 */
class EntityFactory extends Factory
{
    public function definition(): array
    {
        $name = ucwords(fake()->unique()->words(2, true));

        return [
            'type' => fake()->randomElement(EntityType::cases()),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'source_url' => fake()->url(),
            'metadata' => [],
            'provider' => 'factory',
        ];
    }
}
```

- [ ] **Step 4: Run it** — `./vendor/bin/pest tests/Feature/EntityModelTest.php` — Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Enums app/Models/Entity.php database/migrations database/factories/EntityFactory.php tests/Feature/EntityModelTest.php
git commit -m "feat: add Entity model with type enum and unique (type, slug)"
```

---

### Task 3: Slack signature middleware, routes group, test helper

**Files:**
- Create: `app/Http/Middleware/VerifySlackSignature.php`
- Create: `tests/Concerns/SignsSlackRequests.php`
- Modify: `bootstrap/app.php` (CSRF exclusion)
- Modify: `config/services.php` (slack signing secret)
- Test: `tests/Feature/Slack/VerifySlackSignatureTest.php`

- [ ] **Step 1: Add the signing secret to `config/services.php`**

Add to the returned array:

```php
'slack' => [
    'signing_secret' => env('SLACK_SIGNING_SECRET'),
],
```

- [ ] **Step 2: Write the test helper** — `tests/Concerns/SignsSlackRequests.php`

Slack signs the raw body (`v0:{timestamp}:{body}`); in feature tests we pass both parsed parameters (for `input()`) and the raw body (for `getContent()`).

```php
<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

trait SignsSlackRequests
{
    protected function postSlack(string $uri, array $data, array $headerOverrides = []): TestResponse
    {
        $body = http_build_query($data);
        $timestamp = $headerOverrides['timestamp'] ?? (string) now()->getTimestamp();
        $signature = $headerOverrides['signature']
            ?? 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", config('services.slack.signing_secret'));

        return $this->call('POST', $uri, $data, [], [], [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
    }
}
```

- [ ] **Step 3: Write the failing tests** — `tests/Feature/Slack/VerifySlackSignatureTest.php`

The middleware is tested against a throwaway route so it doesn't depend on later controller tasks.

```php
<?php

use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

beforeEach(function () {
    Route::post('test-slack', fn () => response()->json(['ok' => true]))
        ->middleware(VerifySlackSignature::class);
});

it('accepts a correctly signed request', function () {
    $this->postSlack('test-slack', ['text' => 'hi'])->assertSuccessful();
});

it('rejects a bad signature', function () {
    $this->postSlack('test-slack', ['text' => 'hi'], ['signature' => 'v0=deadbeef'])
        ->assertUnauthorized();
});

it('rejects a stale timestamp', function () {
    $stale = (string) now()->subMinutes(10)->getTimestamp();
    $body = http_build_query(['text' => 'hi']);
    $signature = 'v0='.hash_hmac('sha256', "v0:{$stale}:{$body}", config('services.slack.signing_secret'));

    $this->postSlack('test-slack', ['text' => 'hi'], ['timestamp' => $stale, 'signature' => $signature])
        ->assertUnauthorized();
});

it('rejects missing headers', function () {
    $this->post('test-slack', ['text' => 'hi'])->assertUnauthorized();
});
```

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Feature/Slack` — Expected: FAIL (middleware class not found).

- [ ] **Step 5: Implement** — `app/Http/Middleware/VerifySlackSignature.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySlackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.slack.signing_secret');
        abort_if(blank($secret), 500, 'Slack signing secret is not configured.');

        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');
        abort_if(blank($timestamp) || blank($signature), 401);
        abort_if(abs(now()->getTimestamp() - (int) $timestamp) > 300, 401);

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$request->getContent()}", $secret);
        abort_unless(hash_equals($expected, $signature), 401);

        return $next($request);
    }
}
```

- [ ] **Step 6: Exclude `slack/*` from CSRF** — in `bootstrap/app.php`, inside `->withMiddleware(...)`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['slack/*']);
})
```

(The test route `test-slack` is registered without the `web` group in tests so CSRF doesn't apply to it; the exclusion is for the real routes added in Tasks 6–8.)

- [ ] **Step 7: Run them** — `./vendor/bin/pest tests/Feature/Slack` — Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware config/services.php bootstrap/app.php tests/Concerns tests/Feature/Slack
git commit -m "feat: verify Slack request signatures"
```

---

### Task 4: EntitySearchService (fuzzy ranking)

**Files:**
- Create: `app/Services/EntitySearchService.php`
- Test: `tests/Feature/EntitySearchServiceTest.php` (Feature because it reads the DB)

- [ ] **Step 1: Write the failing tests** — `tests/Feature/EntitySearchServiceTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Models\Entity;
use App\Services\EntitySearchService;

beforeEach(function () {
    $this->search = new EntitySearchService();
});

function makeEntity(string $name, EntityType $type = EntityType::Card): Entity
{
    return Entity::factory()->create([
        'type' => $type,
        'name' => $name,
        'slug' => Str::slug($name),
    ]);
}

it('ranks exact match above prefix above substring', function () {
    makeEntity('Strike Out');       // prefix
    makeEntity('Counter Strike');   // substring
    makeEntity('Strike');           // exact

    $results = $this->search->search('strike');

    expect($results->pluck('name')->all())
        ->toBe(['Strike', 'Strike Out', 'Counter Strike']);
});

it('matches despite typos', function () {
    makeEntity('Hardstone');

    expect($this->search->search('ardstone')->pluck('name')->all())
        ->toContain('Hardstone');
});

it('returns nothing for gibberish', function () {
    makeEntity('Ball Lightning');

    expect($this->search->search('zzqxv'))->toBeEmpty();
});

it('returns both entities when a name exists in two types', function () {
    makeEntity('Anchor', EntityType::Card);
    makeEntity('Anchor', EntityType::Relic);

    expect($this->search->search('anchor'))->toHaveCount(2);
});

it('respects the limit', function () {
    foreach (range(1, 30) as $i) {
        makeEntity("Fireball {$i}");
    }

    expect($this->search->search('fireball', 25))->toHaveCount(25);
});

it('returns nothing for an empty query', function () {
    makeEntity('Strike');

    expect($this->search->search('  '))->toBeEmpty();
});
```

Add `use Illuminate\Support\Str;` at the top of the test file.

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Feature/EntitySearchServiceTest.php` — Expected: FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Services/EntitySearchService.php`

```php
<?php

namespace App\Services;

use App\Models\Entity;
use Illuminate\Support\Collection;

class EntitySearchService
{
    private const FUZZY_THRESHOLD = 0.35;

    /**
     * @return Collection<int, Entity>
     */
    public function search(string $query, int $limit = 10): Collection
    {
        $query = $this->normalize($query);

        if ($query === '') {
            return new Collection();
        }

        return Entity::query()
            ->get()
            ->map(fn (Entity $entity) => [
                'entity' => $entity,
                'score' => $this->score($query, $this->normalize($entity->name)),
            ])
            ->filter(fn (array $row) => $row['score'] > 0.0)
            ->sortBy([
                fn (array $a, array $b) => $b['score'] <=> $a['score'],
                fn (array $a, array $b) => strcmp($a['entity']->name, $b['entity']->name),
            ])
            ->take($limit)
            ->pluck('entity')
            ->values();
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value)));
    }

    private function score(string $query, string $name): float
    {
        if ($name === $query) {
            return 100.0;
        }

        if (str_starts_with($name, $query)) {
            return 90.0;
        }

        if (str_contains($name, $query)) {
            return 75.0;
        }

        $similarity = $this->trigramSimilarity($query, $name);

        return $similarity >= self::FUZZY_THRESHOLD ? $similarity * 70.0 : 0.0;
    }

    private function trigramSimilarity(string $a, string $b): float
    {
        $ta = $this->trigrams($a);
        $tb = $this->trigrams($b);

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($ta, $tb));

        return (2 * $intersection) / (count($ta) + count($tb));
    }

    /**
     * @return list<string>
     */
    private function trigrams(string $value): array
    {
        $padded = '  '.$value.' ';
        $grams = [];

        for ($i = 0, $max = mb_strlen($padded) - 2; $i < $max; $i++) {
            $grams[] = mb_substr($padded, $i, 3);
        }

        return array_values(array_unique($grams));
    }
}
```

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Feature/EntitySearchServiceTest.php` — Expected: PASS (6 tests). If the typo test fails, lower `FUZZY_THRESHOLD` to `0.3` and re-run.

- [ ] **Step 5: Commit**

```bash
git add app/Services tests/Feature/EntitySearchServiceTest.php
git commit -m "feat: trigram fuzzy entity search"
```

---

### Task 5: BlockKitFormatter

**Files:**
- Create: `app/Slack/BlockKitFormatter.php`
- Test: `tests/Unit/BlockKitFormatterTest.php`

- [ ] **Step 1: Write the failing tests** — `tests/Unit/BlockKitFormatterTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Models\Entity;
use App\Slack\BlockKitFormatter;

beforeEach(function () {
    $this->formatter = new BlockKitFormatter();
});

function fakeEntity(array $attributes = []): Entity
{
    return Entity::factory()->make(array_merge([
        'id' => 7,
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'description' => 'Deal 7 damage. Channel 1 Lightning.',
        'source_url' => 'https://sts2.untapped.gg/en/cards/ball-lightning',
        'metadata' => ['cost' => '1', 'rarity' => 'Common', 'character' => 'Defect', 'card_type' => 'Attack'],
    ], $attributes));
}

it('renders an entity card', function () {
    $blocks = $this->formatter->entityCard(fakeEntity());

    expect($blocks[0])->toBe([
        'type' => 'header',
        'text' => ['type' => 'plain_text', 'text' => 'Ball Lightning', 'emoji' => true],
    ]);
    expect($blocks[1]['elements'][0]['text'])->toBe('Card · Defect · Common · 1 Cost · Attack');
    expect($blocks[2]['text']['text'])->toBe('Deal 7 damage. Channel 1 Lightning.');
    expect($blocks[3]['elements'][0]['text'])
        ->toBe('<https://sts2.untapped.gg/en/cards/ball-lightning|View on sts2.untapped.gg>');
});

it('omits the source block when there is no url', function () {
    $blocks = $this->formatter->entityCard(fakeEntity(['source_url' => null]));

    expect($blocks)->toHaveCount(3);
});

it('renders the select prompt with an external select', function () {
    $payload = $this->formatter->selectPrompt();

    expect($payload['response_type'])->toBe('ephemeral')
        ->and($payload['blocks'][0]['accessory']['type'])->toBe('external_select')
        ->and($payload['blocks'][0]['accessory']['action_id'])->toBe('sts_entity_select')
        ->and($payload['blocks'][0]['accessory']['min_query_length'])->toBe(2);
});

it('renders a search response with did-you-mean buttons', function () {
    $top = fakeEntity();
    $other = fakeEntity(['id' => 8, 'name' => 'Ball of Fire', 'type' => EntityType::Potion]);

    $payload = $this->formatter->searchResponse($top, collect([$other]));

    $actions = collect($payload['blocks'])->firstWhere('type', 'actions');
    expect($payload['response_type'])->toBe('ephemeral')
        ->and($actions['elements'][0]['value'])->toBe('8')
        ->and($actions['elements'][0]['action_id'])->toBe('sts_entity_button_8')
        ->and($actions['elements'][0]['text']['text'])->toBe('Ball of Fire · Potion');
});

it('renders a search response without buttons when unambiguous', function () {
    $payload = $this->formatter->searchResponse(fakeEntity(), collect());

    expect(collect($payload['blocks'])->firstWhere('type', 'actions'))->toBeNull();
});

it('renders options for the external select', function () {
    $card = fakeEntity();
    $relic = fakeEntity(['id' => 9, 'name' => 'Anchor', 'type' => EntityType::Relic]);

    $payload = $this->formatter->options(collect([$card, $relic]));

    expect($payload['options'])->toBe([
        ['text' => ['type' => 'plain_text', 'text' => 'Ball Lightning · Card'], 'value' => '7'],
        ['text' => ['type' => 'plain_text', 'text' => 'Anchor · Relic'], 'value' => '9'],
    ]);
});

it('renders a no-results message', function () {
    $payload = $this->formatter->noResults('zzqxv');

    expect($payload['response_type'])->toBe('ephemeral')
        ->and($payload['text'])->toContain('zzqxv');
});
```

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Unit/BlockKitFormatterTest.php` — Expected: FAIL (class not found).

- [ ] **Step 3: Implement** — `app/Slack/BlockKitFormatter.php`

```php
<?php

namespace App\Slack;

use App\Models\Entity;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BlockKitFormatter
{
    private const MAX_DID_YOU_MEAN = 4;

    /**
     * @return list<array<string, mixed>>
     */
    public function entityCard(Entity $entity): array
    {
        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => $entity->name, 'emoji' => true],
            ],
            [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => $this->contextLine($entity)]],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $entity->description],
            ],
        ];

        if ($entity->source_url) {
            $host = parse_url($entity->source_url, PHP_URL_HOST);
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => "<{$entity->source_url}|View on {$host}>"]],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    public function selectPrompt(): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => 'Search Slay the Spire 2',
            'blocks' => [[
                'type' => 'section',
                'block_id' => 'sts_search',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*Search Slay the Spire 2:* type to filter cards, relics, potions, enemies, events, and enchants.',
                ],
                'accessory' => [
                    'type' => 'external_select',
                    'action_id' => 'sts_entity_select',
                    'placeholder' => ['type' => 'plain_text', 'text' => 'Search entities…'],
                    'min_query_length' => 2,
                ],
            ]],
        ];
    }

    /**
     * @param  Collection<int, Entity>  $others
     * @return array<string, mixed>
     */
    public function searchResponse(Entity $top, Collection $others): array
    {
        $blocks = $this->entityCard($top);

        if ($others->isNotEmpty()) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => '_Did you mean:_']],
            ];
            $blocks[] = [
                'type' => 'actions',
                'block_id' => 'sts_did_you_mean',
                'elements' => $others
                    ->take(self::MAX_DID_YOU_MEAN)
                    ->map(fn (Entity $entity) => [
                        'type' => 'button',
                        'action_id' => "sts_entity_button_{$entity->id}",
                        'text' => ['type' => 'plain_text', 'text' => $this->optionLabel($entity)],
                        'value' => (string) $entity->id,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'response_type' => 'ephemeral',
            'text' => $top->name,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  Collection<int, Entity>  $entities
     * @return array<string, mixed>
     */
    public function options(Collection $entities): array
    {
        return [
            'options' => $entities
                ->map(fn (Entity $entity) => [
                    'text' => ['type' => 'plain_text', 'text' => $this->optionLabel($entity)],
                    'value' => (string) $entity->id,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function noResults(string $query): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => "No Slay the Spire 2 entities match \"{$query}\".",
        ];
    }

    private function contextLine(Entity $entity): string
    {
        $metadata = $entity->metadata ?? [];

        $parts = array_filter([
            $entity->type->label(),
            $metadata['character'] ?? null,
            $metadata['rarity'] ?? null,
            isset($metadata['cost']) ? "{$metadata['cost']} Cost" : null,
            $metadata['card_type'] ?? null,
        ]);

        return implode(' · ', $parts);
    }

    private function optionLabel(Entity $entity): string
    {
        return Str::limit("{$entity->name} · {$entity->type->label()}", 70, '…');
    }
}
```

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Unit/BlockKitFormatterTest.php` — Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Slack tests/Unit/BlockKitFormatterTest.php
git commit -m "feat: Block Kit formatter for entity cards, select prompt, options"
```

---

### Task 6: /slack/command endpoint

**Files:**
- Create: `app/Http/Controllers/Slack/CommandController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Slack/CommandTest.php`

- [ ] **Step 1: Write the failing tests** — `tests/Feature/Slack/CommandTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

it('returns the select prompt when no text is given', function () {
    $this->postSlack('/slack/command', ['text' => ''])
        ->assertSuccessful()
        ->assertJsonPath('blocks.0.accessory.type', 'external_select');
});

it('returns the top match for a query', function () {
    Entity::factory()->create([
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning',
        'description' => 'Deal 7 damage.',
    ]);

    $this->postSlack('/slack/command', ['text' => 'ball lightning'])
        ->assertSuccessful()
        ->assertJsonPath('blocks.0.text.text', 'Ball Lightning');
});

it('offers did-you-mean buttons when ambiguous', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'name' => 'Anchor', 'slug' => 'anchor']);
    Entity::factory()->create(['type' => EntityType::Relic, 'name' => 'Anchor', 'slug' => 'anchor']);

    $response = $this->postSlack('/slack/command', ['text' => 'anchor'])->assertSuccessful();

    $actions = collect($response->json('blocks'))->firstWhere('type', 'actions');
    expect($actions['elements'])->toHaveCount(1);
});

it('reports when nothing matches', function () {
    $this->postSlack('/slack/command', ['text' => 'zzqxv'])
        ->assertSuccessful()
        ->assertJsonPath('text', 'No Slay the Spire 2 entities match "zzqxv".');
});

it('rejects unsigned requests', function () {
    $this->post('/slack/command', ['text' => 'hi'])->assertUnauthorized();
});
```

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Feature/Slack/CommandTest.php` — Expected: FAIL (404, route missing).

- [ ] **Step 3: Implement**

`app/Http/Controllers/Slack/CommandController.php`:

```php
<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\EntitySearchService;
use App\Slack\BlockKitFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandController extends Controller
{
    public function __invoke(
        Request $request,
        EntitySearchService $search,
        BlockKitFormatter $formatter,
    ): JsonResponse {
        $text = trim((string) $request->input('text', ''));

        if ($text === '') {
            return response()->json($formatter->selectPrompt());
        }

        $results = $search->search($text, 5);

        if ($results->isEmpty()) {
            return response()->json($formatter->noResults($text));
        }

        return response()->json($formatter->searchResponse($results->first(), $results->slice(1)));
    }
}
```

Add to `routes/web.php`:

```php
use App\Http\Controllers\Slack\CommandController;
use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;

Route::middleware(VerifySlackSignature::class)->prefix('slack')->group(function () {
    Route::post('command', CommandController::class);
});
```

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Feature/Slack/CommandTest.php` — Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers routes/web.php tests/Feature/Slack/CommandTest.php
git commit -m "feat: /sts slash command endpoint"
```

---

### Task 7: /slack/options endpoint

**Files:**
- Create: `app/Http/Controllers/Slack/OptionsController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Slack/OptionsTest.php`

Slack sends `payload=<json>` (form-encoded) with `type: block_suggestion` and the typed `value`.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/Slack/OptionsTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

function optionsPayload(string $value): array
{
    return ['payload' => json_encode([
        'type' => 'block_suggestion',
        'action_id' => 'sts_entity_select',
        'value' => $value,
    ])];
}

it('returns fuzzy-matched options', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'name' => 'Ball Lightning', 'slug' => 'ball-lightning']);
    Entity::factory()->create(['type' => EntityType::Relic, 'name' => 'Anchor', 'slug' => 'anchor']);

    $this->postSlack('/slack/options', optionsPayload('ball'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'options')
        ->assertJsonPath('options.0.text.text', 'Ball Lightning · Card');
});

it('caps options at 25', function () {
    foreach (range(1, 30) as $i) {
        Entity::factory()->create(['type' => EntityType::Card, 'name' => "Fireball {$i}", 'slug' => "fireball-{$i}"]);
    }

    $this->postSlack('/slack/options', optionsPayload('fireball'))
        ->assertSuccessful()
        ->assertJsonCount(25, 'options');
});

it('returns no options for an empty query', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'name' => 'Strike', 'slug' => 'strike']);

    $this->postSlack('/slack/options', optionsPayload(''))
        ->assertSuccessful()
        ->assertJsonCount(0, 'options');
});
```

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Feature/Slack/OptionsTest.php` — Expected: FAIL (404).

- [ ] **Step 3: Implement**

`app/Http/Controllers/Slack/OptionsController.php`:

```php
<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\EntitySearchService;
use App\Slack\BlockKitFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptionsController extends Controller
{
    public function __invoke(
        Request $request,
        EntitySearchService $search,
        BlockKitFormatter $formatter,
    ): JsonResponse {
        $payload = json_decode((string) $request->input('payload'), true) ?? [];
        $query = (string) ($payload['value'] ?? '');

        return response()->json($formatter->options($search->search($query, 25)));
    }
}
```

Add inside the existing `slack` route group in `routes/web.php`:

```php
Route::post('options', OptionsController::class);
```

(and `use App\Http\Controllers\Slack\OptionsController;` at the top.)

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Feature/Slack/OptionsTest.php` — Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Slack/OptionsController.php routes/web.php tests/Feature/Slack/OptionsTest.php
git commit -m "feat: external-select options endpoint"
```

---

### Task 8: /slack/interactions endpoint

**Files:**
- Create: `app/Http/Controllers/Slack/InteractionsController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Slack/InteractionsTest.php`

Slack sends `payload=<json>` with `type: block_actions`, an `actions` array, and a `response_url`. A select action carries `selected_option.value`; a button carries `value`. We must answer 200 quickly and deliver the card by POSTing to `response_url` with `replace_original`.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/Slack/InteractionsTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

function interactionPayload(array $action): array
{
    return ['payload' => json_encode([
        'type' => 'block_actions',
        'response_url' => 'https://hooks.slack.test/response',
        'actions' => [$action],
    ])];
}

it('replaces the message with the card when a select option is chosen', function () {
    Http::fake();
    $entity = Entity::factory()->create([
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning',
    ]);

    $this->postSlack('/slack/interactions', interactionPayload([
        'action_id' => 'sts_entity_select',
        'selected_option' => ['value' => (string) $entity->id],
    ]))->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/response'
        && $request['replace_original'] === true
        && $request['blocks'][0]['text']['text'] === 'Ball Lightning');
});

it('handles did-you-mean button presses', function () {
    Http::fake();
    $entity = Entity::factory()->create([
        'type' => EntityType::Relic,
        'name' => 'Anchor',
        'slug' => 'anchor',
    ]);

    $this->postSlack('/slack/interactions', interactionPayload([
        'action_id' => "sts_entity_button_{$entity->id}",
        'value' => (string) $entity->id,
    ]))->assertSuccessful();

    Http::assertSent(fn ($request) => $request['blocks'][0]['text']['text'] === 'Anchor');
});

it('reports a vanished entity gracefully', function () {
    Http::fake();

    $this->postSlack('/slack/interactions', interactionPayload([
        'action_id' => 'sts_entity_select',
        'selected_option' => ['value' => '999'],
    ]))->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['text'], 'no longer'));
});
```

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Feature/Slack/InteractionsTest.php` — Expected: FAIL (404).

- [ ] **Step 3: Implement**

`app/Http/Controllers/Slack/InteractionsController.php`:

```php
<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Slack\BlockKitFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class InteractionsController extends Controller
{
    public function __invoke(Request $request, BlockKitFormatter $formatter): Response
    {
        $payload = json_decode((string) $request->input('payload'), true) ?? [];
        $action = $payload['actions'][0] ?? [];
        $responseUrl = $payload['response_url'] ?? null;

        $entityId = $action['selected_option']['value'] ?? $action['value'] ?? null;

        if (! $responseUrl || $entityId === null) {
            return response()->noContent();
        }

        $entity = Entity::find($entityId);

        $message = $entity
            ? [
                'response_type' => 'ephemeral',
                'replace_original' => true,
                'text' => $entity->name,
                'blocks' => $formatter->entityCard($entity),
            ]
            : [
                'response_type' => 'ephemeral',
                'replace_original' => true,
                'text' => 'That entity no longer exists. Try /sts again.',
            ];

        Http::post($responseUrl, $message);

        return response()->noContent();
    }
}
```

Add inside the `slack` route group:

```php
Route::post('interactions', InteractionsController::class);
```

(and the corresponding `use` import.)

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Feature/Slack/InteractionsTest.php` — Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Slack/InteractionsController.php routes/web.php tests/Feature/Slack/InteractionsTest.php
git commit -m "feat: interactions endpoint for select and did-you-mean"
```

---

### Task 9: Import contracts and config

**Files:**
- Create: `app/Import/ImportedEntity.php`
- Create: `app/Import/ImportProvider.php`
- Create: `config/sts.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/ImportedEntityTest.php`

- [ ] **Step 1: Write the failing test** — `tests/Unit/ImportedEntityTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Import\ImportedEntity;

it('is a readonly value object', function () {
    $imported = new ImportedEntity(
        type: EntityType::Relic,
        name: 'Akabeko',
        description: 'At the start of each combat, gain 8 Vigor.',
        sourceUrl: 'https://sts2.untapped.gg/en/relics/akabeko',
        metadata: ['rarity' => 'Uncommon'],
    );

    expect($imported->type)->toBe(EntityType::Relic)
        ->and($imported->metadata)->toBe(['rarity' => 'Uncommon']);
});
```

- [ ] **Step 2: Run it** — `./vendor/bin/pest tests/Unit/ImportedEntityTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Import/ImportedEntity.php`:

```php
<?php

namespace App\Import;

use App\Enums\EntityType;

final readonly class ImportedEntity
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public EntityType $type,
        public string $name,
        public string $description,
        public ?string $sourceUrl = null,
        public array $metadata = [],
    ) {}
}
```

`app/Import/ImportProvider.php`:

```php
<?php

namespace App\Import;

interface ImportProvider
{
    public function name(): string;

    /**
     * @return iterable<ImportedEntity>
     */
    public function entities(): iterable;
}
```

`config/sts.php`:

```php
<?php

use App\Import\FixtureProvider;
use App\Import\UntappedProvider;

return [
    'default_provider' => env('STS_IMPORT_PROVIDER', 'fixture'),

    'providers' => [
        'fixture' => FixtureProvider::class,
        'untapped' => UntappedProvider::class,
    ],

    'fixture_path' => env('STS_FIXTURE_PATH') ?: database_path('data'),

    'untapped' => [
        'base_url' => env('STS_UNTAPPED_BASE_URL', 'https://sts2.untapped.gg'),
        'delay_ms' => (int) env('STS_UNTAPPED_DELAY_MS', 150),
    ],
];
```

`app/Providers/AppServiceProvider.php` — add to `register()`:

```php
use App\Import\FixtureProvider;
use App\Import\UntappedProvider;

public function register(): void
{
    $this->app->when(FixtureProvider::class)->needs('$path')->give(fn () => config('sts.fixture_path'));
    $this->app->when(UntappedProvider::class)->needs('$baseUrl')->give(fn () => config('sts.untapped.base_url'));
    $this->app->when(UntappedProvider::class)->needs('$delayMs')->give(fn () => config('sts.untapped.delay_ms'));
}
```

(Referencing the not-yet-written provider classes in config and bindings is fine — they're only resolved on use; Tasks 10 and 12 create them. The full suite at this point must still pass: nothing instantiates them yet.)

- [ ] **Step 4: Run it** — `./vendor/bin/pest tests/Unit/ImportedEntityTest.php` — Expected: PASS. Then `./vendor/bin/pest` — Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Import config/sts.php app/Providers/AppServiceProvider.php tests/Unit/ImportedEntityTest.php
git commit -m "feat: import provider contract, DTO, and sts config"
```

---

### Task 10: FixtureProvider

**Files:**
- Create: `app/Import/FixtureProvider.php`
- Test: `tests/Unit/FixtureProviderTest.php`

- [ ] **Step 1: Write the failing tests** — `tests/Unit/FixtureProviderTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Import\FixtureProvider;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->path = storage_path('framework/testing/fixtures-'.uniqid());
    File::ensureDirectoryExists($this->path);
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

it('yields entities from every json file in the directory', function () {
    File::put($this->path.'/cards.json', json_encode([[
        'type' => 'card',
        'name' => 'Ball Lightning',
        'description' => 'Deal 7 damage.',
        'source_url' => 'https://example.test/cards/ball-lightning',
        'metadata' => ['cost' => '1'],
    ]]));
    File::put($this->path.'/relics.json', json_encode([[
        'type' => 'relic',
        'name' => 'Akabeko',
        'description' => 'Gain 8 Vigor.',
    ]]));

    $entities = collect((new FixtureProvider($this->path))->entities());

    expect($entities)->toHaveCount(2)
        ->and($entities->firstWhere('name', 'Ball Lightning'))
        ->type->toBe(EntityType::Card)
        ->metadata->toBe(['cost' => '1'])
        ->and($entities->firstWhere('name', 'Akabeko'))
        ->sourceUrl->toBeNull()
        ->metadata->toBe([]);
});

it('yields nothing from an empty directory', function () {
    expect(collect((new FixtureProvider($this->path))->entities()))->toBeEmpty();
});

it('throws on malformed json', function () {
    File::put($this->path.'/broken.json', '{nope');

    collect((new FixtureProvider($this->path))->entities());
})->throws(JsonException::class);
```

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Unit/FixtureProviderTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement** — `app/Import/FixtureProvider.php`

```php
<?php

namespace App\Import;

use App\Enums\EntityType;

final class FixtureProvider implements ImportProvider
{
    public function __construct(private readonly string $path) {}

    public function name(): string
    {
        return 'fixture';
    }

    public function entities(): iterable
    {
        foreach (glob($this->path.'/*.json') ?: [] as $file) {
            $records = json_decode(
                (string) file_get_contents($file),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );

            foreach ($records as $record) {
                yield new ImportedEntity(
                    type: EntityType::from($record['type']),
                    name: $record['name'],
                    description: $record['description'],
                    sourceUrl: $record['source_url'] ?? null,
                    metadata: $record['metadata'] ?? [],
                );
            }
        }
    }
}
```

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Unit/FixtureProviderTest.php` — Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Import/FixtureProvider.php tests/Unit/FixtureProviderTest.php
git commit -m "feat: fixture import provider reading database/data json"
```

---

### Task 11: sts:import Artisan command

**Files:**
- Create: `app/Console/Commands/ImportEntities.php`
- Test: `tests/Feature/ImportCommandTest.php`

- [ ] **Step 1: Write the failing tests** — `tests/Feature/ImportCommandTest.php`

The fake provider lives in the test file; it's registered into `config('sts.providers')` per test.

```php
<?php

use App\Enums\EntityType;
use App\Import\ImportedEntity;
use App\Import\ImportProvider;
use App\Models\Entity;
use Illuminate\Support\Facades\File;

final class FakeProvider implements ImportProvider
{
    /** @var list<ImportedEntity> */
    public static array $entities = [];

    public function name(): string
    {
        return 'fake';
    }

    public function entities(): iterable
    {
        return self::$entities;
    }
}

beforeEach(function () {
    config()->set('sts.providers.fake', FakeProvider::class);
    FakeProvider::$entities = [
        new ImportedEntity(EntityType::Card, 'Ball Lightning', 'Deal 7 damage.', 'https://example.test/bl', ['cost' => '1']),
        new ImportedEntity(EntityType::Relic, 'Akabeko', 'Gain 8 Vigor.'),
    ];
});

it('creates entities from the provider', function () {
    $this->artisan('sts:import', ['--provider' => 'fake'])
        ->expectsOutputToContain('2 created, 0 updated')
        ->assertSuccessful();

    expect(Entity::count())->toBe(2)
        ->and(Entity::firstWhere('slug', 'ball-lightning'))
        ->name->toBe('Ball Lightning')
        ->provider->toBe('fake')
        ->metadata->toBe(['cost' => '1']);
});

it('updates instead of duplicating on re-import', function () {
    $this->artisan('sts:import', ['--provider' => 'fake'])->assertSuccessful();

    FakeProvider::$entities[0] = new ImportedEntity(EntityType::Card, 'Ball Lightning', 'Deal 8 damage.');

    $this->artisan('sts:import', ['--provider' => 'fake'])
        ->expectsOutputToContain('0 created, 2 updated')
        ->assertSuccessful();

    expect(Entity::count())->toBe(2)
        ->and(Entity::firstWhere('slug', 'ball-lightning')->description)->toBe('Deal 8 damage.');
});

it('uses the configured default provider', function () {
    config()->set('sts.default_provider', 'fake');

    $this->artisan('sts:import')->assertSuccessful();

    expect(Entity::count())->toBe(2);
});

it('fails on an unknown provider', function () {
    $this->artisan('sts:import', ['--provider' => 'nope'])
        ->expectsOutputToContain('Unknown provider')
        ->assertFailed();
});

it('writes fixtures when asked', function () {
    $path = storage_path('framework/testing/fixtures-'.uniqid());
    config()->set('sts.fixture_path', $path);

    $this->artisan('sts:import', ['--provider' => 'fake', '--save-fixtures' => true])
        ->assertSuccessful();

    $cards = json_decode(File::get($path.'/cards.json'), true);
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['name'])->toBe('Ball Lightning')
        ->and($cards[0]['metadata'])->toBe(['cost' => '1']);

    File::deleteDirectory($path);
});
```

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Feature/ImportCommandTest.php` — Expected: FAIL (command not found).

- [ ] **Step 3: Implement** — `app/Console/Commands/ImportEntities.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportEntities extends Command
{
    protected $signature = 'sts:import
        {--provider= : Provider name from config/sts.php (defaults to sts.default_provider)}
        {--save-fixtures : Write all entities back to the fixture files after importing}';

    protected $description = 'Import Slay the Spire 2 entities from a configured provider';

    public function handle(): int
    {
        $name = $this->option('provider') ?: config('sts.default_provider');
        $providers = config('sts.providers', []);

        if (! isset($providers[$name])) {
            $this->error("Unknown provider [{$name}]. Available: ".implode(', ', array_keys($providers)));

            return self::FAILURE;
        }

        $provider = $this->laravel->make($providers[$name]);

        $created = 0;
        $updated = 0;

        foreach ($provider->entities() as $imported) {
            $entity = Entity::updateOrCreate(
                ['type' => $imported->type, 'slug' => Str::slug($imported->name)],
                [
                    'name' => $imported->name,
                    'description' => $imported->description,
                    'source_url' => $imported->sourceUrl,
                    'metadata' => $imported->metadata,
                    'provider' => $provider->name(),
                ],
            );

            $entity->wasRecentlyCreated ? $created++ : $updated++;
        }

        if ($this->option('save-fixtures')) {
            $this->saveFixtures();
        }

        $this->info("Imported from [{$name}]: {$created} created, {$updated} updated.");

        return self::SUCCESS;
    }

    private function saveFixtures(): void
    {
        $path = config('sts.fixture_path');
        File::ensureDirectoryExists($path);

        Entity::query()
            ->orderBy('type')
            ->orderBy('slug')
            ->get()
            ->groupBy(fn (Entity $entity) => $entity->type->value)
            ->each(function ($entities, string $type) use ($path) {
                $records = $entities->map(fn (Entity $entity) => [
                    'type' => $entity->type->value,
                    'name' => $entity->name,
                    'description' => $entity->description,
                    'source_url' => $entity->source_url,
                    'metadata' => $entity->metadata ?? [],
                ])->values();

                File::put(
                    "{$path}/{$type}s.json",
                    $records->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );

                $this->line("Wrote {$entities->count()} {$type}s to {$type}s.json");
            });
    }
}
```

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Feature/ImportCommandTest.php` — Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console tests/Feature/ImportCommandTest.php
git commit -m "feat: sts:import command with provider resolution and fixture export"
```

---

### Task 12: UntappedProvider

**Files:**
- Create: `app/Import/UntappedProvider.php`
- Test: `tests/Unit/UntappedProviderTest.php`

Strategy (verified in the spike): per section (`cards`, `relics`, `potions`, `events`), read `{base}/sitemap/{section}.xml`, collect unique `/en/{section}/{slug}` URLs, fetch each detail page, extract:
- **name** from `<title>` (text before first `–`, minus a `(Event)` suffix)
- **description** from static `<meta name="description" content="…">`, falling back to the flight-payload pattern `\"name\":\"description\",\"content\":\"…\"`
- **metadata** by parsing the description prefix (cards: cost/rarity/card_type/character; relics & potions: rarity/character); events keep the whole text and no metadata. Unparseable prefixes degrade gracefully: full text as description, empty metadata.

- [ ] **Step 1: Write the failing tests** — `tests/Unit/UntappedProviderTest.php`

```php
<?php

use App\Enums\EntityType;
use App\Import\UntappedProvider;
use Illuminate\Support\Facades\Http;

const BASE = 'https://sts2.test';

function sitemapXml(string $section, array $slugs): string
{
    $urls = collect($slugs)
        ->map(fn (string $slug) => '<url><loc>'.BASE."/en/{$section}/{$slug}</loc></url>")
        ->implode('');

    return '<?xml version="1.0" encoding="UTF-8"?><urlset>'.$urls.'</urlset>';
}

function detailHtml(string $title, ?string $metaDescription, ?string $flightDescription = null): string
{
    $meta = $metaDescription !== null
        ? '<meta name="description" content="'.e($metaDescription).'"/>'
        : '';
    $flight = $flightDescription !== null
        ? '<script>self.__next_f.push([1,"[\"$\",\"meta\",\"1\",{\"name\":\"description\",\"content\":\"'
            .str_replace('"', '\\\\\"', $flightDescription).'\"}]"])</script>'
        : '';

    return "<html><head><title>{$title}</title>{$meta}</head><body>{$flight}</body></html>";
}

beforeEach(function () {
    $this->provider = new UntappedProvider(baseUrl: BASE, delayMs: 0);
});

it('imports a card with parsed metadata', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', ['ball-lightning'])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/cards/ball-lightning' => Http::response(detailHtml(
            'Ball Lightning – Defect Common Attack – Slay the Spire 2 Card – Untapped.gg',
            'Ball Lightning is a 1-Cost Common Attack card in the Defect pool: Deal 7 damage. Channel 1 Lightning.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities)->toHaveCount(1)
        ->and($entities->first())
        ->type->toBe(EntityType::Card)
        ->name->toBe('Ball Lightning')
        ->description->toBe('Deal 7 damage. Channel 1 Lightning.')
        ->sourceUrl->toBe(BASE.'/en/cards/ball-lightning')
        ->metadata->toBe([
            'cost' => '1',
            'rarity' => 'Common',
            'card_type' => 'Attack',
            'character' => 'Defect',
        ]);
});

it('imports a relic and a potion with parsed metadata', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', [])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', ['akabeko'])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', ['ashwater'])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/relics/akabeko' => Http::response(detailHtml(
            'Akabeko – Slay the Spire 2 Relic – Untapped.gg',
            'Akabeko is a Uncommon relic in the Colorless pool: At the start of each combat, gain 8 Vigor.',
        )),
        BASE.'/en/potions/ashwater' => Http::response(detailHtml(
            'Ashwater – Slay the Spire 2 Potion – Untapped.gg',
            'Ashwater is a Uncommon potion in the Ironclad pool: Exhaust any number of cards in your Hand.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities)->toHaveCount(2)
        ->and($entities->firstWhere('name', 'Akabeko'))
        ->type->toBe(EntityType::Relic)
        ->description->toBe('At the start of each combat, gain 8 Vigor.')
        ->metadata->toBe(['rarity' => 'Uncommon', 'character' => 'Colorless'])
        ->and($entities->firstWhere('name', 'Ashwater'))
        ->type->toBe(EntityType::Potion)
        ->metadata->toBe(['rarity' => 'Uncommon', 'character' => 'Ironclad']);
});

it('imports an event from the flight payload', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', [])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', ['abyssal-baths'])),
        BASE.'/en/events/abyssal-baths' => Http::response(detailHtml(
            'Abyssal Baths (Event) – Slay the Spire 2 – Untapped.gg',
            null,
            'You discover a secluded chamber.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities)->toHaveCount(1)
        ->and($entities->first())
        ->type->toBe(EntityType::Event)
        ->name->toBe('Abyssal Baths')
        ->description->toBe('You discover a secluded chamber.')
        ->metadata->toBe([]);
});

it('skips pages that fail or cannot be parsed', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', ['broken', 'missing'])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/cards/broken' => Http::response('<html><head><title>Broken</title></head></html>'),
        BASE.'/en/cards/missing' => Http::response('', 404),
    ]);

    expect(collect($this->provider->entities()))->toBeEmpty();
});

it('falls back gracefully when the description prefix does not parse', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', ['weird'])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/cards/weird' => Http::response(detailHtml(
            'Weird Card – Untapped.gg',
            'Some unstructured description text.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities->first())
        ->name->toBe('Weird Card')
        ->description->toBe('Some unstructured description text.')
        ->metadata->toBe([]);
});
```

- [ ] **Step 2: Run them** — `./vendor/bin/pest tests/Unit/UntappedProviderTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement** — `app/Import/UntappedProvider.php`

```php
<?php

namespace App\Import;

use App\Enums\EntityType;
use Illuminate\Support\Facades\Http;

final class UntappedProvider implements ImportProvider
{
    private const SECTIONS = [
        'cards' => EntityType::Card,
        'relics' => EntityType::Relic,
        'potions' => EntityType::Potion,
        'events' => EntityType::Event,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $delayMs,
    ) {}

    public function name(): string
    {
        return 'untapped';
    }

    public function entities(): iterable
    {
        foreach (self::SECTIONS as $section => $type) {
            foreach ($this->detailUrls($section) as $url) {
                if ($this->delayMs > 0) {
                    usleep($this->delayMs * 1000);
                }

                $entity = $this->parsePage($type, $url);

                if ($entity !== null) {
                    yield $entity;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function detailUrls(string $section): array
    {
        $xml = Http::timeout(30)->get("{$this->baseUrl}/sitemap/{$section}.xml")->throw()->body();

        preg_match_all(
            '#<loc>('.preg_quote($this->baseUrl, '#')."/en/{$section}/[a-z0-9-]+)</loc>#",
            $xml,
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }

    private function parsePage(EntityType $type, string $url): ?ImportedEntity
    {
        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            return null;
        }

        $html = $response->body();
        $name = $this->extractName($html);
        $description = $this->extractDescription($html);

        if ($name === null || $description === null) {
            return null;
        }

        [$description, $metadata] = $this->parseDescription($type, $description);

        return new ImportedEntity($type, $name, $description, $url, $metadata);
    }

    private function extractName(string $html): ?string
    {
        if (! preg_match('/<title>([^<]+)<\/title>/', $html, $m)) {
            return null;
        }

        $name = trim(explode('–', html_entity_decode($m[1], ENT_QUOTES))[0]);

        return preg_replace('/\s*\(Event\)$/', '', $name) ?: null;
    }

    private function extractDescription(string $html): ?string
    {
        if (preg_match('/<meta name="description" content="([^"]*)"/', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        // Next.js flight payload: \"name\":\"description\",\"content\":\"…\"
        if (preg_match('/\\\\"name\\\\":\\\\"description\\\\",\\\\"content\\\\":\\\\"((?:[^"\\\\]|\\\\.)*?)\\\\"/s', $html, $m)) {
            $decoded = json_decode('"'.str_replace('\\\\', '\\', $m[1]).'"');

            return is_string($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseDescription(EntityType $type, string $description): array
    {
        $pattern = match ($type) {
            EntityType::Card => '/^.+? is an? (?<cost>\d+|X)-Cost (?<rarity>[A-Za-z]+) (?<card_type>[A-Za-z]+) card in the (?<character>[A-Za-z\' ]+) pool: (?<desc>.+)$/s',
            EntityType::Relic => '/^.+? is an? (?<rarity>[A-Za-z]+) relic in the (?<character>[A-Za-z\' ]+) pool: (?<desc>.+)$/s',
            EntityType::Potion => '/^.+? is an? (?<rarity>[A-Za-z]+) potion in the (?<character>[A-Za-z\' ]+) pool: (?<desc>.+)$/s',
            default => null,
        };

        if ($pattern === null || ! preg_match($pattern, $description, $m)) {
            return [$description, []];
        }

        $metadata = array_filter([
            'cost' => $m['cost'] ?? null,
            'rarity' => $m['rarity'] ?? null,
            'card_type' => $m['card_type'] ?? null,
            'character' => $m['character'] ?? null,
        ], fn (?string $value) => $value !== null && $value !== '');

        return [trim($m['desc']), $metadata];
    }
}
```

- [ ] **Step 4: Run them** — `./vendor/bin/pest tests/Unit/UntappedProviderTest.php` — Expected: PASS (5 tests). The flight-payload escaping in test 3 is fiddly; if it fails, debug by dumping the fixture HTML and the regex match rather than weakening the assertion.

- [ ] **Step 5: Run the whole suite** — `./vendor/bin/pest` — Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Import/UntappedProvider.php tests/Unit/UntappedProviderTest.php
git commit -m "feat: untapped.gg scrape provider via sitemaps and meta descriptions"
```

---

### Task 13: Real import, fixtures, README

**Files:**
- Create: `database/data/*.json` (generated)
- Create: `README.md` (overwrite skeleton README)

- [ ] **Step 1: Run the real scrape** (network; ~1,000 pages at 150 ms spacing ≈ 3–4 min)

```bash
php artisan migrate --force
php artisan sts:import --provider=untapped --save-fixtures
```

Expected: `database/data/{cards,relics,potions,events}.json` written. Per the spike, the listing pages showed roughly **584 cards, 292 relics, 63 potions, 56 events** — counts should land near those. The sitemaps list each page twice (`/cards/x` and `/en/cards/x`); the provider regex only matches `/en/`, so if counts come out doubled, the regex is matching both variants — fix the regex, not the data.

- [ ] **Step 2: Spot-check quality**

```bash
php artisan tinker --execute="dump(App\Models\Entity::firstWhere('slug','ball-lightning')->toArray());"
jq length database/data/*.json
```

Expected: sensible name/description/metadata; no HTML entities or escaped junk in descriptions. If descriptions contain `<` spans or markup, fix `extractDescription`/`parseDescription` and re-run the import.

- [ ] **Step 3: Verify the fixture round-trip**

```bash
rm database/database.sqlite && touch database/database.sqlite
php artisan migrate --force
php artisan sts:import
php artisan tinker --execute="dump(App\Models\Entity::count());"
```

Expected: same count as the untapped import; provider column now `fixture`.

- [ ] **Step 4: Write `README.md`** (replace the skeleton one)

```markdown
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

Set `SLACK_SIGNING_SECRET` in `.env` (Slack app → Basic Information →
Signing Secret).

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

## Tests

    ./vendor/bin/pest
```

- [ ] **Step 5: Run the full suite one last time**

Run: `./vendor/bin/pest`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add database/data README.md
git commit -m "feat: ship scraped fixture data and setup docs"
```
