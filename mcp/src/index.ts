#!/usr/bin/env node

import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  GetPromptRequestSchema,
  ListPromptsRequestSchema,
  ListResourcesRequestSchema,
  ListToolsRequestSchema,
  ReadResourceRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";

const siteUrl = process.env.FSYNC_SITE_URL?.trim();
const token = process.env.FSYNC_MCP_TOKEN?.trim();

if (!siteUrl || !token) {
  process.stderr.write("FSYNC_SITE_URL and FSYNC_MCP_TOKEN are required.\n");
  process.exit(1);
}

const base = new URL(siteUrl);
const isLocal = ["localhost", "127.0.0.1", "::1"].includes(base.hostname) || !base.hostname.includes(".");
if (base.protocol !== "https:" && !(base.protocol === "http:" && isLocal)) {
  process.stderr.write("FSYNC_SITE_URL must use HTTPS except for a local development host.\n");
  process.exit(1);
}

const endpoint = new URL(base.toString());
endpoint.searchParams.set("rest_route", "/flares-sync/v1/mcp");
let requestId = 0;

type JsonRpcResult = Record<string, unknown>;

async function remote(method: string, params: Record<string, unknown> = {}): Promise<JsonRpcResult> {
  const response = await fetch(endpoint, {
    method: "POST",
    redirect: "error",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
      "MCP-Protocol-Version": "2025-11-25",
    },
    body: JSON.stringify({ jsonrpc: "2.0", id: ++requestId, method, params }),
    signal: AbortSignal.timeout(120_000),
  });

  if (!response.ok) {
    const body = (await response.text()).slice(0, 500);
    throw new Error(`Yamashin WP Migration MCP returned HTTP ${response.status}: ${body}`);
  }

  const message = (await response.json()) as {
    result?: JsonRpcResult;
    error?: { code?: number; message?: string };
  };
  if (message.error) {
    throw new Error(`Remote MCP ${message.error.code ?? -32000}: ${message.error.message ?? "Unknown error"}`);
  }
  if (!message.result || typeof message.result !== "object") {
    throw new Error("Remote MCP returned an invalid JSON-RPC result.");
  }

  return message.result;
}

const server = new Server(
  { name: "yamashin-wp-migration-mcp", version: "1.0.0" },
  { capabilities: { tools: {}, resources: {}, prompts: {} } },
);

server.setRequestHandler(ListToolsRequestSchema, async () => remote("tools/list") as never);
server.setRequestHandler(CallToolRequestSchema, async (request) =>
  remote("tools/call", { name: request.params.name, arguments: request.params.arguments ?? {} }) as never,
);
server.setRequestHandler(ListResourcesRequestSchema, async () => remote("resources/list") as never);
server.setRequestHandler(ReadResourceRequestSchema, async (request) =>
  remote("resources/read", { uri: request.params.uri }) as never,
);
server.setRequestHandler(ListPromptsRequestSchema, async () => remote("prompts/list") as never);
server.setRequestHandler(GetPromptRequestSchema, async (request) =>
  remote("prompts/get", { name: request.params.name, arguments: request.params.arguments ?? {} }) as never,
);

const transport = new StdioServerTransport();
await server.connect(transport);
