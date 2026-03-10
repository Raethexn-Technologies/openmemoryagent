/**
 * OMA ICP Adapter
 *
 * A tiny Express server that acts as a bridge between the Laravel app
 * and the ICP memory canister. It translates simple JSON HTTP calls
 * into Candid calls against the ICP canister.
 *
 * Laravel calls this adapter on port 3100.
 * This adapter calls the ICP canister via @dfinity/agent.
 *
 * In mock mode (ICP_MOCK=true), it uses an in-memory store so you
 * can develop without a running dfx canister.
 */

const express = require('express');
const { HttpAgent, Actor } = require('@dfinity/agent');
const { Principal } = require('@dfinity/principal');
const { IDL } = require('@dfinity/candid');

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3100;
const CANISTER_ID = process.env.ICP_CANISTER_ID || '';
const ICP_HOST = process.env.ICP_HOST || 'http://localhost:4943';
const MOCK_MODE = process.env.ICP_MOCK !== 'false';

// ─── In-memory mock store ──────────────────────────────────────────
const mockStore = [];

// ─── Candid IDL for the memory canister ───────────────────────────
const idlFactory = ({ IDL }) => {
  const StoreRequest = IDL.Record({
    user_id: IDL.Text,
    session_id: IDL.Text,
    content: IDL.Text,
    metadata: IDL.Opt(IDL.Text),
  });

  const MemoryResponse = IDL.Record({
    id: IDL.Text,
    user_id: IDL.Text,
    session_id: IDL.Text,
    content: IDL.Text,
    timestamp: IDL.Int,
    metadata: IDL.Opt(IDL.Text),
  });

  return IDL.Service({
    store_memory: IDL.Func([StoreRequest], [IDL.Text], []),
    get_memories: IDL.Func([IDL.Text], [IDL.Vec(MemoryResponse)], ['query']),
    get_memories_by_session: IDL.Func([IDL.Text], [IDL.Vec(MemoryResponse)], ['query']),
    list_recent_memories: IDL.Func([IDL.Nat], [IDL.Vec(MemoryResponse)], ['query']),
  });
};

// ─── ICP Actor factory ─────────────────────────────────────────────
async function getActor() {
  const agent = new HttpAgent({ host: ICP_HOST });
  if (ICP_HOST.includes('localhost')) {
    await agent.fetchRootKey().catch(console.warn);
  }
  return Actor.createActor(idlFactory, { agent, canisterId: CANISTER_ID });
}

// ─── Routes ────────────────────────────────────────────────────────

// POST /store
app.post('/store', async (req, res) => {
  const { user_id, session_id, content, metadata } = req.body;

  if (MOCK_MODE) {
    const id = `${user_id}:${Date.now()}`;
    const record = { id, user_id, session_id, content, timestamp: Date.now(), metadata: metadata || null };
    mockStore.push(record);
    return res.json({ id });
  }

  try {
    const actor = await getActor();
    const id = await actor.store_memory({ user_id, session_id, content, metadata: metadata ? [metadata] : [] });
    res.json({ id });
  } catch (err) {
    console.error('store_memory error:', err);
    res.status(500).json({ error: err.message });
  }
});

// GET /memories/:userId
app.get('/memories/:userId', async (req, res) => {
  const { userId } = req.params;

  if (MOCK_MODE) {
    const memories = mockStore.filter(m => m.user_id === userId);
    return res.json({ memories });
  }

  try {
    const actor = await getActor();
    const memories = await actor.get_memories(userId);
    res.json({ memories: memories.map(formatRecord) });
  } catch (err) {
    console.error('get_memories error:', err);
    res.status(500).json({ error: err.message });
  }
});

// GET /memories/session/:sessionId
app.get('/memories/session/:sessionId', async (req, res) => {
  const { sessionId } = req.params;

  if (MOCK_MODE) {
    const memories = mockStore.filter(m => m.session_id === sessionId);
    return res.json({ memories });
  }

  try {
    const actor = await getActor();
    const memories = await actor.get_memories_by_session(sessionId);
    res.json({ memories: memories.map(formatRecord) });
  } catch (err) {
    console.error('get_memories_by_session error:', err);
    res.status(500).json({ error: err.message });
  }
});

// GET /memories/recent?limit=20
app.get('/memories/recent', async (req, res) => {
  const limit = parseInt(req.query.limit || '20', 10);

  if (MOCK_MODE) {
    const memories = mockStore.slice(-limit);
    return res.json({ memories });
  }

  try {
    const actor = await getActor();
    const memories = await actor.list_recent_memories(BigInt(limit));
    res.json({ memories: memories.map(formatRecord) });
  } catch (err) {
    console.error('list_recent_memories error:', err);
    res.status(500).json({ error: err.message });
  }
});

// GET /health
app.get('/health', (_, res) => res.json({ status: 'ok', mock: MOCK_MODE }));

// ─── Helpers ───────────────────────────────────────────────────────
function formatRecord(r) {
  return {
    id: r.id,
    user_id: r.user_id,
    session_id: r.session_id,
    content: r.content,
    timestamp: Number(r.timestamp),
    metadata: r.metadata?.[0] ?? null,
  };
}

app.listen(PORT, () => {
  console.log(`OMA ICP Adapter listening on :${PORT} [mock=${MOCK_MODE}]`);
});
