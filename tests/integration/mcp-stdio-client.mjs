import { Client } from "../../mcp/node_modules/@modelcontextprotocol/sdk/dist/esm/client/index.js";
import { StdioClientTransport } from "../../mcp/node_modules/@modelcontextprotocol/sdk/dist/esm/client/stdio.js";

const transport = new StdioClientTransport({
  command: process.execPath,
  args: [new URL("../../mcp/dist/index.js", import.meta.url).pathname],
  env: {
    ...process.env,
    FSYNC_SITE_URL: process.env.FSYNC_SITE_URL,
    FSYNC_MCP_TOKEN: process.env.FSYNC_MCP_TOKEN,
  },
});
const client = new Client({ name: "fsync-integration-client", version: "1.0.0" });

try {
  await client.connect(transport);
  const tools = await client.listTools();
  const resources = await client.listResources();
  const prompts = await client.listPrompts();
  const status = await client.callTool({ name: "status", arguments: {} });
  const releaseCreate = tools.tools.find((tool) => tool.name === "release_create");
  const directions = releaseCreate?.inputSchema?.properties?.direction?.enum ?? [];
  const refusedApply = await client.callTool({ name: "release_apply", arguments: {} });
  if (
    tools.tools.length < 10 ||
    resources.resources.length < 5 ||
    prompts.prompts.length < 2 ||
    status.isError ||
    !directions.includes("push") ||
    !directions.includes("pull") ||
    !refusedApply.isError
  ) {
    throw new Error("MCP stdio bridge returned incomplete capabilities.");
  }
  process.stdout.write(
    JSON.stringify({
      tools: tools.tools.length,
      resources: resources.resources.length,
      prompts: prompts.prompts.length,
      directions,
      destructive_without_confirmation: "refused",
      status: "ok",
    }) + "\n",
  );
} finally {
  await client.close();
}
