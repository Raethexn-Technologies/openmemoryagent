# OpenMemoryAgent

An experimental chat application that stores AI memory summaries in an ICP canister rather than in the host application's database. The user's browser holds an Ed25519 signing key, and writes to the canister are authenticated with that key so the server cannot write memory records under a user's identity.

[VISION.md](./VISION.md) covers the design decisions and research questions in depth. [DEVLOG.md](./DEVLOG.md) is the running record of what was discovered building it — implementation findings, security fixes, and what remains unresolved.

---

## How it works

The application is a standard Laravel and Vue web app. The interesting part is the memory layer.

When a conversation produces something worth remembering, the server summarizes it, classifies it as public, private, or sensitive, and returns it to the browser. If the user approves (required for private and sensitive records), the browser signs the write with an Ed25519 key from localStorage and sends it to the ICP canister directly. The canister records `msg.caller` as the owner of that record. The server is not in that write path and cannot forge a write under the user's principal.

The LLM only receives public memories when building its context for a response. Private and sensitive records are gated by `msg.caller` on the canister: anonymous callers (the server adapter, the MCP server, and external HTTP clients) receive only public records. An authenticated browser actor calling with the owner's signed identity retrieves the full set.

In mock mode, which is the default for local development, memories are stored in Laravel's file cache rather than in a canister. The consent flow runs identically in both modes, so you can develop and test the full approval flow without any ICP infrastructure.

---

## Memory types

The three memory tiers are the core of the trust model:

| Type | LLM context | Owner panel | Requires approval |
|---|---|---|---|
| public | Yes | Yes | No |
| private | No | Yes | Yes |
| sensitive | No | Yes | Yes |

Public memories are the only records the LLM can recall. Private and sensitive records are owner-gated at the canister level, not just by application code.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.3 |
| Frontend | Vue 3, Inertia.js, Tailwind CSS |
| Database | PostgreSQL (Docker) or SQLite (local development) |
| LLM | OpenRouter, model configurable via `OPENROUTER_MODEL` |
| Memory | ICP canister (Motoko), browser-signed writes, Node.js adapter for server reads |

---

## Quickstart without Docker

```bash
cd app
cp .env.example .env
# Set OPENROUTER_API_KEY in .env — get a key at https://openrouter.ai/keys
php artisan key:generate
composer install
npm install
php artisan migrate
npm run build
php artisan serve
```

Open http://localhost:8000. Memory runs in mock mode by default, so no canister or adapter is needed to get started.

---

## Quickstart with Docker and PostgreSQL

```bash
cp app/.env.example app/.env
# Set OPENROUTER_API_KEY in app/.env

docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open http://localhost:8080.

---

## Mock mode

Setting `ICP_MOCK_MODE=true` (the default) replaces the ICP canister with Laravel's file cache. This is the right way to run the application locally or in CI when you don't have a running dfx replica or deployed canister. The LLM still calls OpenRouter for chat responses, but no ICP tooling is required.

Private and sensitive memories show the same approval dialogs in mock mode that they do in live mode. The only difference is the destination: file cache instead of the canister.

---

## Swapping models

Change `OPENROUTER_MODEL` in `.env` and restart the server. The full model list is at https://openrouter.ai/models.

```env
OPENROUTER_MODEL=anthropic/claude-sonnet-4.5    # default
OPENROUTER_MODEL=google/gemini-2.5-flash         # faster, lower cost
OPENROUTER_MODEL=google/gemini-2.5-flash:free    # free tier, rate-limited
OPENROUTER_MODEL=meta-llama/llama-4-scout:free   # free tier, rate-limited
```

The memory layer stores identical records regardless of which model is in use.

---

## Connecting a real ICP canister

```bash
# Install dfx
sh -ci "$(curl -fsSL https://internetcomputer.org/install.sh)"

# Start a local replica and deploy the canister
cd icp
dfx start --background
dfx deploy
dfx canister id memory   # note this ID for the next steps

# Start the Node adapter in a separate terminal
cd icp/adapter
npm install
ICP_MOCK=false ICP_CANISTER_ID=<canister-id> node server.js

# Update app/.env
ICP_MOCK_MODE=false
ICP_CANISTER_ENDPOINT=http://localhost:3100
ICP_CANISTER_ID=<canister-id>
ICP_BROWSER_HOST=http://localhost:4943    # use https://ic0.app for mainnet
```

---

## Running tests

```bash
cd app
php artisan test
```

The test suite runs against SQLite in-memory and mock mode throughout, so no API key or canister is required.

---

## Project structure

```
OpenMemoryAgent/
├── app/                          # Laravel application
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── ChatController.php
│   │   │   ├── GraphController.php
│   │   │   └── MemoryController.php
│   │   ├── Services/
│   │   │   ├── IcpMemoryService.php
│   │   │   ├── MemorySummarizationService.php
│   │   │   ├── GraphExtractionService.php    # LLM extracts node type, label, tags from each memory
│   │   │   ├── MemoryGraphService.php        # stores nodes, auto-wires edges, neighborhood traversal
│   │   │   └── LLM/
│   │   │       ├── LlmProviderInterface.php
│   │   │       ├── LlmService.php
│   │   │       └── OpenRouterProvider.php
│   │   └── Models/
│   │       ├── Message.php
│   │       ├── MemoryNode.php
│   │       └── MemoryEdge.php
│   ├── resources/js/
│   │   ├── Pages/
│   │   │   ├── Chat/Index.vue        # chat interface and My Memories panel
│   │   │   ├── Memory/Index.vue      # flat memory inspector
│   │   │   └── Memory/Graph.vue      # brain-like graph explorer (D3 force-directed)
│   │   └── composables/
│   │       ├── useIcpIdentity.js     # Ed25519 key generation and localStorage persistence
│   │       └── useIcpMemory.js       # browser-signed writes and owner-authenticated reads
│   └── tests/Feature/
├── icp/
│   ├── src/memory/
│   │   ├── main.mo                   # Motoko canister source
│   │   └── types.mo
│   ├── adapter/
│   │   └── server.js                 # read-only adapter in live mode; mock store in mock mode
│   └── dfx.json
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
├── docker-compose.yml
├── LICENSE
├── CONTRIBUTING.md
├── VISION.md                         # research position: design decisions, what this proves, open questions
└── DEVLOG.md                         # captain's log: what was discovered building it, entry by entry
```

---

## What each layer does

| Layer | Role |
|---|---|
| Laravel | Request handling, LLM orchestration, memory summarization, public-only context retrieval |
| Vue + Inertia | Chat interface, identity management, browser-signed writes, approval dialogs |
| useIcpIdentity.js | Generates an Ed25519 key pair in browser localStorage and derives the ICP principal |
| useIcpMemory.js | Browser actor for signing store_memory calls and retrieving the owner's full record set |
| PostgreSQL | Chat transcript, session data, application-level records |
| IcpMemoryService | Fetches public memories from the adapter for injection into the LLM system prompt |
| ICP adapter | Translates HTTP JSON from Laravel into Candid query calls; read-only in live mode |
| ICP canister | Enforces msg.caller as user_id and serves JSON records over the HTTP gateway |
| OpenRouter | Routes LLM calls to whichever model is set in OPENROUTER_MODEL |
