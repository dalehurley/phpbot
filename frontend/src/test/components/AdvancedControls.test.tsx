import { describe, it, expect, vi } from "vitest"
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { AdvancedControls } from "@/components/AdvancedControls"
import { defaultOverrides } from "@/types/bot"

function makeProps(o = {}) {
  return {
    isOpen: true,
    onToggle: vi.fn(),
    overrides: { ...defaultOverrides },
    onUpdateOverride: vi.fn(),
    onReset: vi.fn(),
    payloadPreview: { prompt: "test", verbose: false },
    ...o,
  }
}

describe("AdvancedControls", () => {
  it("renders heading", () => {
    render(<AdvancedControls {...makeProps()} />)
    expect(screen.getByText("Advanced controls")).toBeInTheDocument()
  })

  it("shows Collapse when open", () => {
    render(<AdvancedControls {...makeProps({ isOpen: true })} />)
    expect(screen.getByRole("button", { name: "Collapse" })).toBeInTheDocument()
  })

  it("shows Expand when closed", () => {
    render(<AdvancedControls {...makeProps({ isOpen: false })} />)
    expect(screen.getByRole("button", { name: "Expand" })).toBeInTheDocument()
  })

  it("calls onToggle", async () => {
    const onToggle = vi.fn()
    render(<AdvancedControls {...makeProps({ onToggle })} />)
    await userEvent.click(screen.getByRole("button", { name: "Collapse" }))
    expect(onToggle).toHaveBeenCalledTimes(1)
  })

  it("hides content when collapsed", () => {
    render(<AdvancedControls {...makeProps({ isOpen: false })} />)
    expect(screen.queryByText("Model")).not.toBeInTheDocument()
  })

  it("shows model fields when expanded", () => {
    render(<AdvancedControls {...makeProps()} />)
    expect(screen.getByText("Model")).toBeInTheDocument()
    expect(screen.getByText("Fast model")).toBeInTheDocument()
  })

  it("shows numeric fields", () => {
    render(<AdvancedControls {...makeProps()} />)
    expect(screen.getByText("Max iterations")).toBeInTheDocument()
    expect(screen.getByText("Temperature")).toBeInTheDocument()
  })

  it("calls onUpdateOverride on input change", async () => {
    const onUpdateOverride = vi.fn()
    render(<AdvancedControls {...makeProps({ onUpdateOverride })} />)
    const modelInput = screen.getAllByDisplayValue("claude-sonnet-4-5")[0]
    await userEvent.clear(modelInput)
    await userEvent.type(modelInput, "new-model")
    expect(onUpdateOverride).toHaveBeenCalled()
  })

  it("calls onReset on Reset defaults click", async () => {
    const onReset = vi.fn()
    render(<AdvancedControls {...makeProps({ onReset })} />)
    await userEvent.click(screen.getByRole("button", { name: "Reset defaults" }))
    expect(onReset).toHaveBeenCalledTimes(1)
  })

  it("shows payload preview JSON", () => {
    render(<AdvancedControls {...makeProps()} />)
    expect(screen.getByText(/"prompt"/)).toBeInTheDocument()
  })
})
