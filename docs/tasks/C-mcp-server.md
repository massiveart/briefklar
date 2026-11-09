# Task C: MCP server (owner: André)

## Goal
Expose briefklar as an MCP server, so any MCP client (Claude Desktop, Claude Code, others) can use it.

## Deliverables
1. Check whether `symfony/mcp-bundle` is available and stable enough in the installed pre-release; otherwise use `mcp/sdk` directly with `Server::builder()` and a console command.
2. Tools:
   - `redact_text(text)`: returns redacted text and the list of entity types found (never the originals).
   - `explain_letter(text)`: runs the same agent as the web app, returns `LetterExplanation` as structured content.
3. Transports: STDIO (for Claude Desktop / Code) and Streamable HTTP inside the Docker setup.
4. README section with a copy-paste config for Claude Desktop and the MCP Inspector command.

## Ideas if time allows (pick at most one)
- Use **elicitation** to ask the user for missing context (e.g. "when did you receive the letter?") before computing a deadline. This is exactly the kind of protocol use the jury looks for.
- Expose the fixture letters as MCP **resources** for easy demoing.

## Done when
Milestone M3: MCP Inspector lists both tools and `explain_letter` works on fixture 01.
