# OpenMemoryAgent

> A conversational AI whose memory lives on open infrastructure instead of private servers.

A Laravel/Vue AI application that stores agent memory summaries in an ICP canister instead of locking them inside a traditional cloud database.

---

## The Idea

Most AI agents store memory in private cloud infrastructure (Redis, Pinecone, PostgreSQL on GCP). That means the agent's memory is **owned and controlled by the platform hosting it**.

OpenMemoryAgent demonstrates a different split:

- The **application** is a normal modern web app (Laravel + Vue + Inertia + Tailwind)
- The **AI memory layer** is stored in an Internet Computer Protocol canister — not in the app's database

**Pitch:** "We're experimenting with what AI memory looks like when identity and write access belong to the user instead of the host app."

### What this demo proves

- **Identity is browser-generated**: an Ed25519 key pair is created in the browser on first load and persisted in `localStorage`. The server never generates or stores the private key.
- **Writes are browser-signed (live ICP mode)**: after the server extracts a memory summary, it returns it to the browser. The browser signs and stores it in the canister using the user's identity — `msg.caller` on the canister is the user's principal, not a value the server supplied.
- **The canister enforces ownership**: `store_memory` is `public shared(msg) func` — `user_id` is always `msg.caller`, never trusted from the request body. The server cannot write under the user's principal.
- **Memory lives outside the app**: records are externally readable at `https://<canister-id>.ic0.app/memory/<principal>` with no dependency on the Laravel server.
- **Memory persists across session resets**: the browser key survives chat resets because it lives in `localStorage`, not the server session.

### Current limitations

- **`localStorage` is single-device**: the Ed25519 key only persists on the browser that generated it. Upgrading to Internet Identity would give multi-device portability — the architecture is otherwise identical.
- **Mock mode writes are server-side**: when `ICP_MOCK_MODE=true` (no canister), the server writes to the file cache using the browser-derived principal. Principal authenticity is not cryptographically enforced in mock mode.
- **Reads are always server-mediated**: the server fetches memory via the adapter to inject it into the system prompt. Direct browser reads are possible but not implemented.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.3) |
| Frontend | Vue 3 + Inertia.js + Tailwind CSS |
| Database | PostgreSQL (app data) / SQLite (local dev) |
| Dev environment | Docker |
| LLM | Swappable — Claude, Gemini, or OpenAI |
| Memory storage | ICP canister (Motoko) — browser-signed writes; Node adapter for server reads |

---

## Architecture

```
Browser (Vue)
  │  Ed25519KeyIdentity — generated in localStorage, never sent to server
  │
  ├─── POST /chat/send { message, principal } ──────────────► Laravel (PHP)
  │                                                              ├── ChatController
  │                                                              ├── LlmService (swappable)
  │                                                              ├── MemorySummarizer
  │                                                              └── IcpMemoryService (reads only)
  │                                                                    │
  │                                                                    │  HTTP JSON (port 3100)
  │                                                                    ▼
  │                                                             ICP Adapter (Node/Express)
  │                                                                    │  Candid — query calls
  │                                                                    ▼
  │◄─── { reply, memory_summary } ─────────────────────────── ICP Memory Canister (Motoko)
  │                                                                    ▲
  │  store_memory({ session_id, content })                            │
  │  signed by Ed25519 identity                                       │
  └─── browser → canister (live mode only) ──────────────────────────┘
       msg.caller = user's principal (enforced by canister)
```

**What stays in PostgreSQL:** sessions, chat transcript, user records, app metadata.

**What lives in ICP:** conversation memory summaries, keyed by the user's browser-derived principal.

**In mock mode** (default): memories are stored in Laravel's file cache. The adapter is not needed. The UI shows an amber "Mock memory" badge. The browser principal is still generated and sent, but the server writes under it (no cryptographic enforcement).

**In ICP live mode**: the browser signs and sends memory writes directly to the canister — the server is not in the write path. The adapter is used by Laravel for reads only (fetching memories to inject into the system prompt). `msg.caller` on the canister is the user's Ed25519 principal, not a value the server can forge.

---

## Quickstart (Local — No Docker)

```bash
# 1. Enter app directory
cd app

# 2. Copy SQLite env
cp .env.sqlite .env

# 3. Add your LLM API key
#    Open .env and set:  CLAUDE_API_KEY=sk-ant-...

# 4. Install and run
composer install
npm install
php artisan migrate
npm run build
php artisan serve
```

Open http://localhost:8000 — memory runs in mock mode by default. No canister required.

---

## Quickstart (Docker — PostgreSQL)

```bash
# 1. Copy and configure .env
cp app/.env.bak app/.env
# Set CLAUDE_API_KEY= in app/.env

# 2. Start all containers (app, nginx, db, icp-adapter)
docker compose up -d

# 3. Run migrations
docker compose exec app php artisan migrate

# 4. Open
open http://localhost:8080
```

In Docker, the app talks to the `icp-adapter` container at `http://icp-adapter:3100`. The adapter runs in mock mode by default (`ICP_MOCK_MODE=true`).

---

## LLM Provider Swap

Change one line in `.env`:

```env
LLM_PROVIDER=claude    # Claude (default)
LLM_PROVIDER=gemini    # Google Gemini
LLM_PROVIDER=openai    # OpenAI
```

The memory layer stores the same records regardless of which LLM is used. That is the point.

---

## Connecting to a Real ICP Canister

The ICP adapter is **required** when `ICP_MOCK_MODE=false`. It bridges the PHP app to the deployed Motoko canister.

```bash
# 1. Install dfx (ICP SDK)
sh -ci "$(curl -fsSL https://internetcomputer.org/install.sh)"

# 2. Start local ICP replica
cd icp
dfx start --background

# 3. Deploy the memory canister
dfx deploy

# 4. Get the canister ID
dfx canister id memory

# 5. Start the adapter (in a separate terminal)
cd icp/adapter
npm install
ICP_MOCK=false ICP_CANISTER_ID=<canister-id> node server.js

# 6. Update app/.env
ICP_MOCK_MODE=false
ICP_CANISTER_ENDPOINT=http://localhost:3100   # Laravel → adapter (server reads)
ICP_CANISTER_ID=<canister-id>                 # displayed in inspector + used for canister URL links
ICP_BROWSER_HOST=http://localhost:4943        # browser → dfx replica (for direct writes)
```

`ICP_BROWSER_HOST` is the URL the **user's browser** uses to reach the dfx replica or ICP mainnet gateway. It is separate from `ICP_CANISTER_ENDPOINT` (which is the server→adapter URL). For mainnet, set `ICP_BROWSER_HOST=https://ic0.app`. The adapter uses `ICP_DFX_HOST` (default `http://localhost:4943`) to reach dfx for its own read calls — set that separately if needed.

---

## Canister HTTP Endpoint

The memory canister exposes memory records over plain HTTP — no Candid or dfx required.

```
# Health / record count
curl https://<canister-id>.ic0.app/memory

# All memories for a user
curl https://<canister-id>.ic0.app/memory/<user_id>
```

Example response:
```json
[
  {
    "id": "abc12-defgh-ijklm-nopqr-cai:0",
    "user_id": "abc12-defgh-ijklm-nopqr-cai",
    "session_id": "550e8400-e29b-41d4-a716-446655440000",
    "content": "User is Anthony, builds AI tools.",
    "timestamp": 1709123456789000000,
    "metadata": "{\"source\":\"chat\",\"provider\":\"claude\"}"
  }
]
```

`user_id` is an ICP principal derived from the browser's Ed25519 key — not a server-assigned ID. The canister enforces this: `msg.caller` on `store_memory()` becomes `user_id`; the request body has no `user_id` field.

This is the concrete proof that memory lives outside the app: the URL works from any browser, any terminal, any app — with no dependency on the Laravel server. In the Memory Inspector (live mode), each record shows a clickable link directly to its canister URL.

Locally with dfx, use:
```bash
# dfx routes HTTP through the replica with a query param
curl "http://localhost:4943/memory/<user_id>?canisterId=<canister-id>"
```

---

## Screens

| Screen | Route | Description |
|---|---|---|
| Chat | `/chat` | Conversational interface with memory-aware responses |
| Memory Inspector | `/memory` | Live view of memory records — shows mode, canister health, and record count |

---

## Demo Script (5 minutes)

### Setup
> The app is running. The chat header shows a **Browser key** badge next to a truncated principal — that's an Ed25519 key pair generated in this browser right now. The server never had the private key. Notice the **Mock memory** or **ICP Live** badge in the nav.

### Step 1 — Introduce yourself
> Say: "My name is Anthony and I build AI tools."
> The agent replies. An emerald notification appears:
> - Mock mode: **"Memory stored (mock):"** — server wrote to file cache under your browser principal.
> - Live mode: **"Memory stored on ICP (browser-signed): … Signed by your browser key · server cannot write this"** — the browser signed and sent the write directly to the canister.

### Step 2 — Show the Memory Inspector
> Click **Memory Inspector** in the nav. Point out:
> - The record's `user_id` is an ICP principal — not a server-assigned ID.
> - In live mode: click the `ic0.app/memory/<principal>` link. That URL works from any browser, any terminal, with no dependency on this app server.

### Step 3 — Reset the chat session
> Click **New session**. The confirm dialog says the memory is preserved.
> After reset, the **Browser key** badge and the same principal reappear — loaded from `localStorage`. The transcript is gone; the identity is not.

### Step 4 — Ask what it remembers
> Say: "What do you remember about me?"
> The agent retrieves the memory and responds with context.

### The point to make
> "That write was signed in the browser. The server returned the summary and got out of the way — it couldn't have written that record under my principal even if it tried. The memory is mine, not the app's."

---

## Project Structure

```
OpenMemoryAgent/
├── app/                          # Laravel application
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── ChatController.php
│   │   │   └── MemoryController.php
│   │   ├── Services/
│   │   │   ├── IcpMemoryService.php
│   │   │   ├── MemorySummarizationService.php
│   │   │   └── LLM/
│   │   │       ├── LlmProviderInterface.php
│   │   │       ├── LlmService.php
│   │   │       ├── ClaudeProvider.php
│   │   │       ├── GeminiProvider.php
│   │   │       └── OpenAIProvider.php
│   │   └── Models/Message.php
│   ├── resources/js/
│   │   ├── Pages/
│   │   │   ├── Chat/Index.vue
│   │   │   └── Memory/Index.vue
│   │   ├── Components/
│   │   │   ├── AppLayout.vue
│   │   │   ├── NavLink.vue
│   │   │   ├── StatCard.vue
│   │   │   ├── ArchNode.vue
│   │   │   └── ArchArrow.vue
│   │   └── composables/
│   │       ├── useIcpIdentity.js  # Ed25519 key generation + localStorage persistence
│   │       └── useIcpMemory.js    # Browser-signed canister write actor
│   └── database/migrations/
├── icp/
│   ├── src/memory/
│   │   ├── main.mo               # Motoko canister — store/retrieve/http_request/health
│   │   └── types.mo
│   ├── adapter/
│   │   └── server.js             # Node adapter — reads only in live mode; full mock in mock mode
│   └── dfx.json
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
└── docker-compose.yml
```

---

## What Each Layer Does

| Layer | Role |
|---|---|
| Laravel | Request handling, LLM orchestration, memory summarization, memory reads |
| Vue + Inertia | Chat UI, identity management, browser-signed ICP writes, Memory Inspector |
| `useIcpIdentity.js` | Generates Ed25519 key in browser localStorage; derives ICP principal |
| `useIcpMemory.js` | Browser actor for signing and sending `store_memory` calls to the canister |
| PostgreSQL | Chat transcript, user records, app-level data |
| IcpMemoryService | Reads memories from adapter for LLM context; mock-or-live switchable |
| ICP adapter (Node) | Reads: HTTP JSON → Candid query calls. Writes: mock mode only |
| ICP canister (Motoko) | Enforces `msg.caller` as `user_id`; serves JSON over HTTP gateway |
| LLM providers | Claude / Gemini / OpenAI — swappable, memory layer unchanged |

---

## Philosophy

> "What if an AI agent's memory was decoupled from the host application?"

Today, AI agents remember you because the operator stored your memory in their infrastructure. OpenMemoryAgent explores what it looks like when that memory lives on a separate layer — one the app uses but does not own.

The current implementation stores distilled memory summaries (not raw transcripts) in an ICP canister, keyed by a browser-generated Ed25519 principal. In live ICP mode, writes are signed by the user's browser key — the server is not in the write path and cannot forge entries under the user's identity. The memory outlives any individual chat session.

The next step is multi-device portability via Internet Identity — swapping `Ed25519KeyIdentity` for a WebAuthn-backed ICP principal. The write path, canister logic, and read flow are unchanged.
