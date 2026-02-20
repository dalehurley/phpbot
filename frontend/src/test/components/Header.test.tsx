import { describe, it, expect } from "vitest"
import { render, screen } from "@testing-library/react"
import { Header } from "@/components/Header"
import type { BotRun } from "@/types/bot"

describe("Header", () => {
  it("renders PhpBot Console eyebrow", () => {
    render(<Header isRunning={false} activeRun={null} />)
    expect(screen.getByText(/PhpBot Console/i)).toBeInTheDocument()
  })

  it("shows Idle when not running", () => {
    render(<Header isRunning={false} activeRun={null} />)
    expect(screen.getByText("Idle")).toBeInTheDocument()
  })

  it("shows Running when running", () => {
    render(<Header isRunning={true} activeRun={null} />)
    expect(screen.getByText("Running")).toBeInTheDocument()
  })

  it("shows no run selected when activeRun is null", () => {
    render(<Header isRunning={false} activeRun={null} />)
    expect(screen.getByText(/No run selected yet/)).toBeInTheDocument()
  })

  it("shows active run start time", () => {
    const run: BotRun = { id: "r1", prompt: "Test", startedAt: "10:30 AM" }
    render(<Header isRunning={false} activeRun={run} />)
    expect(screen.getByText(/Active run started/)).toBeInTheDocument()
    expect(screen.getByText(/10:30 AM/)).toBeInTheDocument()
  })

  it("shows chips", () => {
    render(<Header isRunning={false} activeRun={null} />)
    expect(screen.getByText("CLI-powered")).toBeInTheDocument()
    expect(screen.getByText("Claude Agents")).toBeInTheDocument()
    expect(screen.getByText("Local tools")).toBeInTheDocument()
  })

  it("applies status-live class when running", () => {
    const { container } = render(<Header isRunning={true} activeRun={null} />)
    expect(container.querySelector(".status-live")).toBeTruthy()
  })

  it("applies status-idle class when not running", () => {
    const { container } = render(<Header isRunning={false} activeRun={null} />)
    expect(container.querySelector(".status-idle")).toBeTruthy()
  })
})
