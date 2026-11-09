# Task B: briefklar app (owner: Cornelius)

## Goal
A Symfony web app: upload a letter, get a clear explanation. Uses `pii-shield` through Symfony AI processors, so the app code itself never deals with redaction.

## Deliverables (in order)
1. **App skeleton** in `app/`: current stable Symfony release supported by `symfony/ai-bundle` (check compatibility before choosing), `symfony/ai-bundle`, Twig, Symfony UX (Turbo is enough). `pii-shield` required via path repository `../packages/pii-shield`.
2. **Upload + extraction** (`src/Extraction/`): PDF text layer first (e.g. `smalot/pdfparser`), Tesseract CLI via Symfony Process for images and scanned PDFs. Nothing is stored on disk after the request.
3. **Explanation agent** configured in `config/packages/ai.yaml`, structured output into a DTO:
   ```
   LetterExplanation {
     sender: string            // authority / company
     subject: string           // what it is about, 1 sentence
     summary: string           // plain language, max 5 sentences
     actionRequired: bool
     actions: string[]         // concrete steps
     deadline: ?string         // ISO date if stated, plus the sentence it came from
     amount: ?string
     legalReferences: string[] // e.g. "§ 49 VStG", input for task D
     questionsToAsk: string[]  // what to ask the authority if unclear
   }
   ```
   System prompt must say: explain, do not give legal advice, never invent deadlines.
4. **UI**: one upload page, one result page. Show deadline prominently. Optional toggle "Leichte Sprache" (second prompt variant).
5. **Until task A is ready**: use `NullPiiShield` from the package. Switching to the real shield must be config only.
6. **Provider config**: Hugging Face Inference Providers by default (hackathon credits), Anthropic and Ollama as alternatives via env.

## Tests that must exist
- Functional test: upload fixture 01 as PDF, get a result page with the deadline (platform mocked with `MockAgent` or equivalent, check what the installed version offers).
- Unit tests for the extraction chain.

## Out of scope
User accounts, history, storage of letters.

## Done when
Milestone M1 (with NullPiiShield) and M2 (with real shield) in `docs/PLAN.md`.
