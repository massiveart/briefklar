# Task D: RIS citation resolver (stretch, both)

**Only start after the go/no-go on Wed 18.11. (see `docs/PLAN.md`).**

## Goal
When a letter cites a norm ("gemäß § 49 VStG"), fetch the current wording from Austria's Rechtsinformationssystem (RIS, open government data) and let the explanation refer to it.

## Positioning
Several general-purpose RIS MCP servers already exist (Python, TypeScript). We do not build another general one. Our angle is narrow: resolve exactly the citations found in a letter and feed them into the explanation.

## Deliverables
1. `packages/ris-client`: PSR-18 / Symfony HttpClient based client for the RIS OGD API, method `resolveCitation(string $citation): ?Norm`. Verify the current API version and endpoints first.
2. Citation parser for the common forms: `§ 49 VStG`, `§ 20 Abs. 2 StVO`, `§§ 207 ff BAO`.
3. Agent tool (`#[AsTool]`) in the app that resolves `legalReferences`; show the norm text in an expandable section of the result page.
4. Cache responses (Symfony Cache) to be gentle with the public API.
5. Source attribution in the UI as required by the RIS licence terms (check them).

## Done when
Fixture 01 shows the wording of § 49 VStG next to the explanation.
