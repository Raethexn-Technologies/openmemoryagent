/**
 * OpenMemoryAgent — MCP Server
 *
 * Exposes the ICP memory canister as a Model Context Protocol (MCP) resource.
 * Any MCP-compatible agent (Claude Desktop, Claude Code, etc.) can retrieve
 * a user's public memory records via this server.
 *
 * The server reads from the canister's public HTTP endpoint — no auth required
 * for public memories, which is by design. Private/Sensitive records are never
 * exposed here; they require authenticated canister calls from the owner's browser.
 *
 * Configuration (env vars):
 *   ICP_CANISTER_ID   — deployed canister ID (required)
 *   ICP_HOST          — canister HTTP host (default: https://ic0.app)
 *                       Use http://localhost:4943 for local dfx
 *   USER_PRINCIPAL    — default principal to read (optional)
 *
 * Claude Desktop config (~/.claude/claude_desktop_config.json):
 *   {
 *     "mcpServers": {
 *       "openMemory": {
 *         "command": "node",
 *         "args": ["/absolute/path/to/icp/mcp-server/server.js"],
 *         "env": {
 *           "ICP_CANISTER_ID": "<your-canister-id>",
 *           "ICP_HOST": "http://localhost:4943",
 *           "USER_PRINCIPAL": "<your-principal>"
 *         }
 *       }
 *     }
 *   }
 */

import { McpServer, ResourceTemplate } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const CANISTER_ID    = process.env.ICP_CANISTER_ID || '';
const HOST           = (process.env.ICP_HOST || 'https://ic0.app').replace(/\/$/, '');
const USER_PRINCIPAL = process.env.USER_PRINCIPAL || '';
const IS_LOCAL       = HOST.includes('localhost') || HOST.includes('127.0.0.1');

const server = new McpServer({
  name:    'OpenMemoryAgent',
  version: '0.1.0',
});

// ─── Helpers ────────────────────────────────────────────────────────

function canisterUrl(path) {
  if (IS_LOCAL) {
    // dfx serves HTTP via query param locally
    return `${HOST}${path}?canisterId=${CANISTER_ID}`;
  }
  return `https://${CANISTER_ID}.ic0.app${path}`;
}

async function fetchMemories(principal) {
  if (!CANISTER_ID) {
    throw new Error('ICP_CANISTER_ID is not set. Configure the MCP server with your canister ID.');
  }
  if (!principal) {
    throw new Error('No principal specified. Pass a principal in the resource URI or set USER_PRINCIPAL.');
  }

  const url = canisterUrl(`/memory/${encodeURIComponent(principal)}`);
  const res  = await fetch(url);

  if (!res.ok) {
    throw new Error(`Canister returned ${res.status}: ${await res.text()}`);
  }

  return res.json();
}

function formatMemories(memories, principal) {
  if (!Array.isArray(memories) || memories.length === 0) {
    return `No public memories found for principal: ${principal}`;
  }

  const lines = memories.map((m) => {
    const date = m.timestamp > 1e12
      ? new Date(m.timestamp / 1e6).toLocaleDateString()
      : new Date(m.timestamp).toLocaleDateString();
    return `[${date}] ${m.content}`;
  });

  return [
    `Memory records for ${principal}`,
    `Source: ${canisterUrl(`/memory/${principal}`)}`,
    `Total: ${memories.length} public record(s)`,
    '',
    ...lines,
  ].join('\n');
}

// ─── Resource: user's public memory ──────────────────────────────

// URI pattern: memory://<principal>
// The principal is the ICP identity of the user whose public memories you want.
server.resource(
  'user-memory',
  new ResourceTemplate('memory://{principal}', { list: undefined }),
  async (uri, { principal }) => {
    const memories = await fetchMemories(principal);
    return {
      contents: [{
        uri:      uri.href,
        mimeType: 'text/plain',
        text:     formatMemories(memories, principal),
      }],
    };
  }
);

// ─── Tool: read memories for a principal ─────────────────────────
//
// Agents can call this tool to retrieve memory records for any principal.
// Only Public records are returned — Private/Sensitive are canister-enforced
// and never appear in the HTTP endpoint this server reads from.
//
server.tool(
  'get_memories',
  'Retrieve public memory records for an ICP principal from the OpenMemoryAgent canister.',
  {
    principal: z.string().optional().describe(
      'ICP principal to look up. Defaults to USER_PRINCIPAL env var if not specified.'
    ),
  },
  async ({ principal: reqPrincipal }) => {
    const principal = reqPrincipal || USER_PRINCIPAL;
    const memories  = await fetchMemories(principal);

    if (!Array.isArray(memories) || memories.length === 0) {
      return {
        content: [{ type: 'text', text: `No public memories found for ${principal}.` }],
      };
    }

    return {
      content: [{
        type: 'text',
        text: formatMemories(memories, principal),
      }],
    };
  }
);

// ─── Tool: canister health ────────────────────────────────────────

server.tool(
  'canister_health',
  'Check the health and record count of the OpenMemoryAgent ICP canister.',
  {},
  async () => {
    if (!CANISTER_ID) {
      return { content: [{ type: 'text', text: 'ICP_CANISTER_ID not configured.' }] };
    }
    const url = canisterUrl('/memory');
    const res  = await fetch(url);
    const data = await res.json();
    return {
      content: [{
        type: 'text',
        text: [
          `Canister: ${CANISTER_ID}`,
          `Status:   ${data.status ?? 'unknown'}`,
          `Public records:  ${data.public_count ?? '?'}`,
          `Total records:   ${data.total_count ?? '?'}`,
          `Endpoint: ${canisterUrl('/memory')}`,
        ].join('\n'),
      }],
    };
  }
);

// ─── Start ───────────────────────────────────────────────────────

const transport = new StdioServerTransport();
await server.connect(transport);

if (CANISTER_ID) {
  console.error(`[OMA MCP] Canister: ${CANISTER_ID}`);
  console.error(`[OMA MCP] Host:     ${HOST}`);
  if (USER_PRINCIPAL) console.error(`[OMA MCP] Default principal: ${USER_PRINCIPAL}`);
} else {
  console.error('[OMA MCP] WARNING: ICP_CANISTER_ID not set — tool calls will fail until configured.');
}
