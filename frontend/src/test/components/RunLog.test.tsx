import { describe, it, expect, vi } from "vitest"
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { RunLog } from "@/components/RunLog"
import type { BotRun } from "@/types/bot"

function makeRun(o: Partial<BotRun> = {}): BotRun {
  return { id: "r1", prompt: "Test", startedAt: "10:00 AM", ...o }
}

describe("RunLog", () => {
  it("renders heading", () => {
    render(<RunLog runs={[]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText("Run Log")).toBeInTheDocument()
  })

  it("shows 0 recent when empty", () => {
    render(<RunLog runs={[]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText("0 recent")).toBeInTheDocument()
  })

  it("shows count of runs", () => {
    render(<RunLog runs={[makeRun({ id: "1" }), makeRun({ id: "2" })]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText("2 recent")).toBeInTheDocument()
  })

  it("shows empty state", () => {
    render(<RunLog runs={[]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText(/Run a prompt/)).toBeInTheDocument()
  })

  it("shows run prompt", () => {
    render(<RunLog runs={[makeRun({ prompt: "Hello world" })]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText("Hello world")).toBeInTheDocument()
  })

  it("shows Success chip for completed run", () => {
    const run = makeRun({
      response: { success: true, answer: "done", error: null, iterations: 2, tool_calls: [], token_usage: {}, analysis: {} },
    })
    render(<RunLog runs={[run]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText("Success")).toBeInTheDocument()
    expect(screen.getByText("2 iterations")).toBeInTheDocument()
  })

  it("shows Failed chip", () => {
    render(<RunLog runs={[makeRun({ failed: "Error!" })]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText("Failed")).toBeInTheDocument()
  })

  it("shows Running chip", () => {
    render(<RunLog runs={[makeRun({ liveProgress: [] })]} activeRunId={null} onSelectRun={vi.fn()} />)
    expect(screen.getByText("Running")).toBeInTheDocument()
  })

  it("calls onSelectRun on click", async () => {
    const onSelectRun = vi.fn()
    render(<RunLog runs={[makeRun({ id: "abc", prompt: "Click me" })]} activeRunId={null} onSelectRun={onSelectRun} />)
    await userEvent.click(screen.getByRole("button", { name: /Click me/i }))
    expect(onSelectRun).toHaveBeenCalledWith("abc")
  })

  it("applies run-card-active to active run", () => {
    const { container } = render(
      <RunLog runs={[makeRun({ id: "active" })]} activeRunId="active" onSelectRun={vi.fn()} />
    )
    expect(container.querySelector(".run-card-active")).toBeTruthy()
  })

  it("does not apply run-card-active to non-active run", () => {
    const { container } = render(
      <RunLog runs={[makeRun({ id: "r1" })]} activeRunId="other" onSelectRun={vi.fn()} />
    )
    expect(container.querySelector(".run-card-active")).toBeFalsy()
  })
})
