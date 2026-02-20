import { describe, it, expect, vi } from "vitest"
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { OutputSection } from "@/components/OutputSection"
import type { BotRun } from "@/types/bot"

function makeRun(o: Partial<BotRun> = {}): BotRun {
  return { id: "r1", prompt: "Test", startedAt: "now", ...o }
}

describe("OutputSection", () => {
  it("renders Output heading", () => {
    render(<OutputSection activeRun={null} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("Output")).toBeInTheDocument()
  })

  it("shows select-a-run when no activeRun", () => {
    render(<OutputSection activeRun={null} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText(/Select a run to view details/)).toBeInTheDocument()
  })

  it("shows error for failed run", () => {
    render(<OutputSection activeRun={makeRun({ failed: "Oops" })} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("Oops")).toBeInTheDocument()
  })

  it("shows Running… for in-progress run", () => {
    render(<OutputSection activeRun={makeRun({ liveProgress: [] })} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("Running…")).toBeInTheDocument()
  })

  it("shows waiting for updates when liveProgress empty", () => {
    render(<OutputSection activeRun={makeRun({ liveProgress: [] })} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText(/Waiting for progress updates/)).toBeInTheDocument()
  })

  it("shows live progress entries", () => {
    const run = makeRun({ liveProgress: [{ stage: "routing", message: "Routing...", ts: 1 }] })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("routing")).toBeInTheDocument()
    expect(screen.getByText("Routing...")).toBeInTheDocument()
  })

  it("shows answer when run completed", () => {
    const run = makeRun({
      response: { success: true, answer: "Task done!", error: null, iterations: 1, tool_calls: [], token_usage: {}, analysis: {} },
    })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("Task done!")).toBeInTheDocument()
  })

  it("shows (No answer returned) when answer null", () => {
    const run = makeRun({
      response: { success: false, answer: null, error: null, iterations: 0, tool_calls: [], token_usage: {}, analysis: {} },
    })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("(No answer returned)")).toBeInTheDocument()
  })

  it("shows error section when response has error", () => {
    const run = makeRun({
      response: { success: false, answer: null, error: "Tool failed", iterations: 1, tool_calls: [], token_usage: {}, analysis: {} },
    })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("Tool failed")).toBeInTheDocument()
  })

  it("shows token usage chips", () => {
    const run = makeRun({
      response: { success: true, answer: "done", error: null, iterations: 1, tool_calls: [], token_usage: { total: 500 }, analysis: {} },
    })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("total: 500")).toBeInTheDocument()
  })

  it("shows Refresh button when log_id present", () => {
    const run = makeRun({
      response: { success: true, answer: "done", error: null, iterations: 1, tool_calls: [], token_usage: {}, analysis: {}, log_id: "log-1" },
    })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByRole("button", { name: /Refresh/i })).toBeInTheDocument()
  })

  it("calls onFetchLogs on Refresh click", async () => {
    const onFetchLogs = vi.fn()
    const run = makeRun({
      response: { success: true, answer: "done", error: null, iterations: 1, tool_calls: [], token_usage: {}, analysis: {}, log_id: "log-1" },
    })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={onFetchLogs} />)
    await userEvent.click(screen.getByRole("button", { name: /Refresh/i }))
    expect(onFetchLogs).toHaveBeenCalledTimes(1)
  })

  it("shows Loading… when logLoading", () => {
    const run = makeRun({
      response: { success: true, answer: "done", error: null, iterations: 1, tool_calls: [], token_usage: {}, analysis: {}, log_id: "log-1" },
    })
    render(<OutputSection activeRun={run} logLoading={true} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("Loading…")).toBeInTheDocument()
  })

  it("shows logContent when set", () => {
    const run = makeRun({
      logContent: "Full log here",
      response: { success: true, answer: "done", error: null, iterations: 1, tool_calls: [], token_usage: {}, analysis: {}, log_id: "log-1" },
    })
    render(<OutputSection activeRun={run} logLoading={false} onFetchLogs={vi.fn()} />)
    expect(screen.getByText("Full log here")).toBeInTheDocument()
  })
})
