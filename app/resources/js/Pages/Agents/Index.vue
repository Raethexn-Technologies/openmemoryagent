<template>
  <div class="flex h-screen bg-slate-950 text-slate-100 overflow-hidden">

    <!-- ── Left panel: Agent management ── -->
    <aside class="w-64 flex-shrink-0 border-r border-slate-800 flex flex-col bg-slate-900">
      <div class="px-4 py-4 border-b border-slate-800">
        <div class="flex items-center gap-2 mb-1 flex-wrap">
          <a href="/chat" class="text-slate-500 hover:text-slate-300 text-sm">← Chat</a>
          <span class="text-slate-700">|</span>
          <a href="/memory" class="text-slate-500 hover:text-slate-300 text-sm">Memory</a>
          <span class="text-slate-700">|</span>
          <a href="/graph" class="text-slate-500 hover:text-slate-300 text-sm">Graph</a>
          <span class="text-slate-700">|</span>
          <a href="/3d" class="text-slate-500 hover:text-slate-300 text-sm">3D</a>
        </div>
        <h1 class="text-base font-semibold text-violet-400">Agent Simulation</h1>
        <p class="text-xs text-slate-500 mt-0.5">Collective Physarum dynamics</p>
      </div>

      <!-- Agent list -->
      <div class="flex-1 overflow-y-auto">
        <div class="px-3 py-3 border-b border-slate-800">
          <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">Agents</p>
          <div v-if="agents.length === 0" class="text-xs text-slate-600 py-2">
            No agents yet. Create one below.
          </div>
          <div v-for="agent in agents" :key="agent.id" class="mb-3 bg-slate-800 rounded-lg p-3">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-medium text-slate-200 truncate">{{ agent.name }}</span>
              <button
                @click="deleteAgent(agent.id)"
                class="text-slate-600 hover:text-red-400 text-xs ml-2 flex-shrink-0"
                title="Remove agent"
              >✕</button>
            </div>

            <!-- Trust score slider -->
            <div class="mb-2">
              <div class="flex justify-between text-xs text-slate-500 mb-1">
                <span>Trust</span>
                <span :class="trustColor(agent.trust_score)">{{ (agent.trust_score * 100).toFixed(0) }}%</span>
              </div>
              <input
                type="range"
                min="0" max="1" step="0.05"
                :value="agent.trust_score"
                @change="updateTrust(agent.id, parseFloat($event.target.value))"
                class="w-full h-1 accent-violet-500"
              />
            </div>

            <div class="flex gap-1.5">
              <button
                @click="seedAgent(agent.id)"
                :disabled="seeding[agent.id]"
                class="flex-1 px-2 py-1 text-xs bg-slate-700 hover:bg-slate-600 rounded disabled:opacity-40"
              >
                {{ seeding[agent.id] ? 'Seeding…' : 'Seed' }}
              </button>
              <button
                @click="simulateAgent(agent.id)"
                :disabled="simulating[agent.id]"
                class="flex-1 px-2 py-1 text-xs bg-violet-700 hover:bg-violet-600 rounded disabled:opacity-40"
              >
                {{ simulating[agent.id] ? 'Running…' : 'Run' }}
              </button>
            </div>

            <div v-if="agent.access_count > 0" class="mt-1.5 text-xs text-slate-600">
              {{ agent.access_count }} run{{ agent.access_count === 1 ? '' : 's' }}
            </div>
          </div>
        </div>

        <!-- Create agent form -->
        <div class="px-3 py-3">
          <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">New agent</p>
          <input
            v-model="newAgentName"
            type="text"
            placeholder="Agent name"
            @keyup.enter="createAgent"
            class="w-full px-3 py-1.5 text-sm bg-slate-800 border border-slate-700 rounded text-slate-200 placeholder-slate-500 focus:outline-none focus:border-violet-500 mb-2"
          />
          <div class="flex justify-between text-xs text-slate-500 mb-1">
            <span>Trust score</span>
            <span>{{ (newAgentTrust * 100).toFixed(0) }}%</span>
          </div>
          <input
            type="range" min="0" max="1" step="0.05"
            v-model.number="newAgentTrust"
            class="w-full h-1 accent-violet-500 mb-2"
          />
          <button
            @click="createAgent"
            :disabled="!newAgentName.trim() || creating"
            class="w-full px-3 py-1.5 text-xs bg-violet-700 hover:bg-violet-600 rounded disabled:opacity-40"
          >
            {{ creating ? 'Creating…' : 'Create agent' }}
          </button>
        </div>
      </div>

      <!-- Demo seed -->
      <div class="px-3 py-3 border-t border-slate-800">
        <p class="text-xs font-medium text-slate-500 mb-2 uppercase tracking-wide">Demo</p>
        <p class="text-xs text-slate-600 mb-2 leading-relaxed">
          Seeds 40 realistic memories, wires edges, runs Physarum turns, and creates Nexus, Beacon, and Ghost agents.
        </p>
        <label class="flex items-center gap-2 mb-2 cursor-pointer">
          <input type="checkbox" v-model="demoFresh" class="accent-violet-500" />
          <span class="text-xs text-slate-400">Reset existing data first</span>
        </label>
        <button
          @click="runDemoSimulation"
          :disabled="simulatingDemo"
          class="w-full px-3 py-1.5 text-xs bg-slate-700 hover:bg-slate-600 rounded disabled:opacity-40 disabled:cursor-not-allowed mb-1"
        >
          {{ simulatingDemo ? 'Seeding…' : 'Seed demo day' }}
        </button>
        <div v-if="demoResult" class="text-xs text-emerald-400 text-center mt-1">
          {{ demoResult }}
        </div>
        <div v-if="demoError" class="text-xs text-red-400 text-center mt-1">
          {{ demoError }}
        </div>
      </div>

      <!-- Run all -->
      <div class="px-3 py-3 border-t border-slate-800">
        <button
          @click="simulateAll"
          :disabled="agents.length === 0 || simulatingAll"
          class="w-full px-3 py-2 text-sm font-medium bg-violet-600 hover:bg-violet-500 rounded disabled:opacity-40 disabled:cursor-not-allowed"
        >
          {{ simulatingAll ? 'Running all…' : 'Run all agents' }}
        </button>
      </div>
    </aside>

    <!-- ── Main panel: Simulation results ── -->
    <main class="flex-1 overflow-hidden flex flex-col">

      <!-- Results header -->
      <div class="border-b border-slate-800 px-6 py-3 flex items-center justify-between bg-slate-900">
        <div>
          <h2 class="text-sm font-semibold text-slate-200">Simulation results</h2>
          <p class="text-xs text-slate-500">
            Each column shows one agent's retrieved context ordered by collective weight.
            Nodes highlighted in violet appear in multiple agents' active sets.
          </p>
        </div>
        <div class="text-xs text-slate-500 text-right">
          <div>{{ sharedEdges.length }} shared edge{{ sharedEdges.length === 1 ? '' : 's' }}</div>
          <div v-if="lastRunAt" class="text-slate-600">Last run: {{ lastRunAt }}</div>
        </div>
      </div>

      <div class="flex-1 overflow-hidden flex">

        <!-- Agent columns -->
        <div class="flex-1 overflow-x-auto overflow-y-auto p-4">
          <div v-if="results.length === 0 && agents.length === 0" class="flex items-center justify-center h-full">
            <div class="text-center max-w-sm">
              <div class="text-5xl mb-4 opacity-30">⬡</div>
              <div class="text-base font-semibold text-slate-300 mb-2">No agents yet</div>
              <div class="text-sm text-slate-500 mb-5 leading-relaxed">
                Click "Seed demo day" in the left panel to instantly populate the graph with 40 realistic memories and three agents, then watch the collective Physarum weights accumulate.
              </div>
              <a href="/3d" class="inline-block px-4 py-2 text-xs rounded border border-violet-700 text-violet-400 hover:bg-violet-900/30 transition-colors">
                Open Mission Control →
              </a>
            </div>
          </div>

          <div v-else-if="results.length === 0 && agents.length > 0" class="flex items-center justify-center h-full">
            <div class="text-center max-w-sm">
              <div class="text-5xl mb-4 opacity-30">⬡</div>
              <div class="text-base font-semibold text-slate-300 mb-2">Ready to simulate</div>
              <div class="text-sm text-slate-500 leading-relaxed">
                Seed each agent with your memories, then click "Run all agents" to watch collective weights emerge.
              </div>
            </div>
          </div>

          <div v-else class="flex gap-4 min-w-max">
            <div
              v-for="result in results"
              :key="result.agent_id"
              class="w-72 flex-shrink-0"
            >
              <!-- Agent column header -->
              <div class="rounded-t-lg px-3 py-2.5 border-b border-slate-700 bg-slate-800">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="text-sm font-semibold text-violet-300">{{ result.agent_name }}</span>
                  <span :class="['text-xs font-mono font-bold', trustColor(result.trust_score)]">
                    {{ (result.trust_score * 100).toFixed(0) }}%
                  </span>
                </div>
                <!-- Trust bar -->
                <div class="h-1 rounded-full bg-slate-700 overflow-hidden mb-1.5">
                  <div
                    class="h-full rounded-full transition-all duration-500"
                    :style="{
                      width: (result.trust_score * 100) + '%',
                      background: result.trust_score >= 0.7 ? '#4ade80' : result.trust_score >= 0.4 ? '#facc15' : '#f87171'
                    }"
                  ></div>
                </div>
                <div class="text-xs text-slate-500">
                  {{ result.context.length }} node{{ result.context.length === 1 ? '' : 's' }} retrieved
                </div>
              </div>

              <!-- Retrieved nodes -->
              <div class="bg-slate-800 rounded-b-lg divide-y divide-slate-700">
                <div
                  v-for="node in result.context"
                  :key="node.id"
                  :class="[
                    'px-3 py-2.5 transition-all duration-300',
                    isSharedNode(node)
                      ? 'bg-violet-950/60 border-l-2 border-violet-400 shadow-[inset_0_0_12px_rgba(139,92,246,0.12)]'
                      : 'border-l-2 border-transparent'
                  ]"
                >
                  <div class="flex items-start gap-2 mb-1.5">
                    <div class="flex-1 min-w-0">
                      <p class="text-xs text-slate-300 leading-relaxed line-clamp-3">{{ node.content }}</p>
                    </div>
                    <span
                      v-if="isSharedNode(node)"
                      class="text-xs text-violet-400 flex-shrink-0 font-medium"
                    >shared</span>
                  </div>
                  <div v-if="node.collective_weight > 0" class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                      <div
                        class="h-full rounded-full transition-all duration-500"
                        :style="{
                          width: Math.max(4, node.collective_weight * 100) + '%',
                          background: isSharedNode(node) ? '#8b5cf6' : '#334155'
                        }"
                      ></div>
                    </div>
                    <span class="text-xs text-slate-500 font-mono flex-shrink-0">{{ node.collective_weight.toFixed(2) }}</span>
                  </div>
                </div>

                <div v-if="result.context.length === 0" class="px-3 py-4 text-xs text-slate-600 text-center">
                  No memories yet. Seed this agent first.
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right panel: shared edges -->
        <div class="w-72 flex-shrink-0 border-l border-slate-800 overflow-y-auto bg-slate-900 flex flex-col">
          <div class="px-4 py-3 border-b border-slate-800">
            <h3 class="text-xs font-medium text-slate-400 uppercase tracking-wide">Collective weights</h3>
            <p class="text-xs text-slate-600 mt-0.5">Shared edges between agents sorted by accumulated weight</p>
          </div>

          <div v-if="sharedEdges.length === 0" class="px-4 py-6 text-xs text-slate-600 text-center">
            No shared edges yet. Run the simulation to build collective weights.
          </div>

          <div
            v-for="edge in sharedEdges"
            :key="edge.id"
            class="px-4 py-3 border-b border-slate-800 hover:bg-slate-800 transition-colors"
          >
            <div class="flex items-center gap-1.5 mb-1.5">
              <span class="text-xs text-violet-400 font-medium">{{ edge.agent_a }}</span>
              <span class="text-slate-600 text-xs">↔</span>
              <span class="text-xs text-violet-400 font-medium">{{ edge.agent_b }}</span>
            </div>
            <p class="text-xs text-slate-400 leading-relaxed line-clamp-2 mb-2">{{ edge.content_preview }}</p>
            <div class="flex items-center gap-2">
              <div class="flex-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                <div
                  class="h-full bg-violet-500 rounded-full transition-all duration-500"
                  :style="{ width: (edge.weight * 100) + '%' }"
                ></div>
              </div>
              <span class="text-xs font-mono text-violet-300 flex-shrink-0">{{ edge.weight.toFixed(3) }}</span>
            </div>
            <div class="text-xs text-slate-600 mt-1">{{ edge.access_count }} reinforcement{{ edge.access_count === 1 ? '' : 's' }}</div>
          </div>
        </div>

      </div>
    </main>

  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  agents: { type: Array, default: () => [] },
  shared_edges: { type: Array, default: () => [] },
})

const agents = ref([...props.agents])
const sharedEdges = ref([...props.shared_edges])
const results = ref([])
const lastRunAt = ref(null)

const newAgentName = ref('')
const newAgentTrust = ref(0.5)
const creating = ref(false)
const seeding = ref({})
const simulating = ref({})
const simulatingAll = ref(false)
const simulatingDemo = ref(false)
const demoFresh = ref(false)
const demoResult = ref(null)
const demoError = ref(null)

// Content strings that appear in more than one agent's current result set.
// Agent partitions have distinct node IDs, so node-id overlap never captures
// semantic sharing across agents.
const sharedContents = computed(() => {
  const agentIdsByContent = new Map()
  for (const result of results.value) {
    const seenInAgent = new Set()
    for (const node of result.context) {
      if (!node.content || seenInAgent.has(node.content)) continue
      seenInAgent.add(node.content)
      if (!agentIdsByContent.has(node.content)) {
        agentIdsByContent.set(node.content, new Set())
      }
      agentIdsByContent.get(node.content).add(result.agent_id)
    }
  }
  return new Set(
    [...agentIdsByContent.entries()]
      .filter(([, agentIds]) => agentIds.size > 1)
      .map(([content]) => content),
  )
})

function isSharedNode(node) {
  return sharedContents.value.has(node.content)
}

function trustColor(score) {
  if (score >= 0.75) return 'text-emerald-400'
  if (score >= 0.45) return 'text-amber-400'
  return 'text-red-400'
}

async function createAgent() {
  if (!newAgentName.value.trim() || creating.value) return
  creating.value = true
  try {
    const res = await fetch('/api/agents', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
      body: JSON.stringify({ name: newAgentName.value.trim(), trust_score: newAgentTrust.value }),
    })
    if (res.ok) {
      agents.value.push(await res.json())
      newAgentName.value = ''
      newAgentTrust.value = 0.5
    }
  } finally {
    creating.value = false
  }
}

async function deleteAgent(agentId) {
  const res = await fetch(`/api/agents/${agentId}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': csrfToken() },
  })
  if (res.ok) {
    agents.value = agents.value.filter(a => a.id !== agentId)
    results.value = results.value.filter(r => r.agent_id !== agentId)
    const sharedRes = await fetch('/api/agents/shared-edges')
    sharedEdges.value = sharedRes.ok ? (await sharedRes.json()).shared_edges ?? [] : []
  }
}

async function updateTrust(agentId, value) {
  const agent = agents.value.find(a => a.id === agentId)
  if (!agent) return
  agent.trust_score = value
  await fetch(`/api/agents/${agentId}/trust`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    body: JSON.stringify({ trust_score: value }),
  })
}

async function seedAgent(agentId) {
  seeding.value[agentId] = true
  try {
    const res = await fetch(`/api/agents/${agentId}/seed`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken() },
    })
    const data = await res.json()
    if (data.seeded !== undefined) {
      // Brief acknowledgement without a modal
      const agent = agents.value.find(a => a.id === agentId)
      if (agent) agent._seeded = (agent._seeded ?? 0) + data.seeded
    }
  } finally {
    seeding.value[agentId] = false
  }
}

async function simulateAgent(agentId) {
  simulating.value[agentId] = true
  try {
    const res = await fetch(`/api/agents/${agentId}/simulate`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken() },
    })
    const data = await res.json()
    mergeResult(data)
    sharedEdges.value = data.shared_edges ?? sharedEdges.value

    const agent = agents.value.find(a => a.id === agentId)
    if (agent) agent.access_count = (agent.access_count ?? 0) + 1

    lastRunAt.value = new Date().toLocaleTimeString()
  } finally {
    simulating.value[agentId] = false
  }
}

async function simulateAll() {
  if (simulatingAll.value) return
  simulatingAll.value = true
  try {
    const res = await fetch('/api/agents/simulate-all', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken() },
    })
    const data = await res.json()
    results.value = data.results ?? []
    sharedEdges.value = data.shared_edges ?? []

    for (const result of results.value) {
      const agent = agents.value.find(a => a.id === result.agent_id)
      if (agent) agent.access_count = (agent.access_count ?? 0) + 1
    }

    lastRunAt.value = new Date().toLocaleTimeString()
  } finally {
    simulatingAll.value = false
  }
}

function mergeResult(data) {
  const idx = results.value.findIndex(r => r.agent_id === data.agent_id)
  if (idx >= 0) {
    results.value[idx] = data
  } else {
    results.value.push(data)
  }
}

async function runDemoSimulation() {
  if (simulatingDemo.value) return
  simulatingDemo.value = true
  demoResult.value = null
  demoError.value = null
  try {
    const url = '/api/demo/simulate-day' + (demoFresh.value ? '?fresh=1' : '')
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken() },
    })
    const data = await res.json()
    if (!res.ok) {
      demoError.value = data.error ?? 'Simulation failed.'
      return
    }
    agents.value = data.agents_list ?? agents.value
    results.value = []
    lastRunAt.value = null
    const sharedRes = await fetch('/api/agents/shared-edges')
    if (sharedRes.ok) {
      const sharedData = await sharedRes.json()
      sharedEdges.value = sharedData.shared_edges ?? []
    } else {
      sharedEdges.value = []
    }
    demoResult.value = `${data.nodes} nodes, ${data.edges} edges, ${data.agents} agents, ${data.shared_edges} shared edges`
  } catch (e) {
    demoError.value = 'Request failed. Check the console.'
  } finally {
    simulatingDemo.value = false
  }
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}
</script>
