import { describe, it, expect, vi } from "vitest"
import { render, screen } from "@testing-library/react"
import App from "@/App"

vi.mock("@/hooks/useBotState", () => ({
  useBotState: () => ({
    prompt: "",
    setPrompt: vi.fn(),
    isRunning: false,
    verbose: false,
    setVerbose: vi.fn(),
    runs: [],
    activeRunId: null,
    setActiveRunId: vi.fn(),
    activeRun: null,
    advancedOpen: true,
    setAdvancedOpen: vi.fn(),
    overrides: {
      model: "claude-sonnet-4-5",
      fast_model: "claude-haiku-4-5",
      super_model: "claude-opus-4-5",
      max_iterations: 20,
      max_tokens: 4096,
      temperature: 0.7,
      timeout: 120,
    },
    updateOverride: vi.fn(),
    resetOverrides: vi.fn(),
    payloadPreview: { prompt: "", verbose: false },
    logLoading: false,
    runBot: vi.fn(),
    clearOutput: vi.fn(),
    fetchLogs: vi.fn(),
  }),
}))

describe("App", () => {
  it("renders without crashing", () => {
    render(<App />)
    expect(screen.getByText("PhpBot Console")).toBeInTheDocument()
  })

  it("renders all main sections", () => {
    render(<App />)
    expect(screen.getByText("Prompt")).toBeInTheDocument()
    expect(screen.getByText("Run Log")).toBeInTheDocument()
    expect(screen.getByText("Output")).toBeInTheDocument()
    expect(screen.getByText("Skill Builder")).toBeInTheDocument()
    expect(screen.getByText("Tool Builder")).toBeInTheDocument()
    expect(screen.getByText("Advanced controls")).toBeInTheDocument()
  })
})
