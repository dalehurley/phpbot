import { describe, it, expect, vi } from "vitest"
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { ToolBuilder } from "@/components/ToolBuilder"

function makeProps(o = {}) {
  return { onLoadPrompt: vi.fn(), onAppendPrompt: vi.fn(), ...o }
}

describe("ToolBuilder", () => {
  it("renders heading", () => {
    render(<ToolBuilder {...makeProps()} />)
    expect(screen.getByText("Tool Builder")).toBeInTheDocument()
  })

  it("renders all fields", () => {
    render(<ToolBuilder {...makeProps()} />)
    expect(screen.getByText("Tool name")).toBeInTheDocument()
    expect(screen.getByText("Purpose and behavior")).toBeInTheDocument()
    expect(screen.getByText("Inputs / parameters")).toBeInTheDocument()
    expect(screen.getByText("Outputs")).toBeInTheDocument()
    expect(screen.getByText("Constraints")).toBeInTheDocument()
  })

  it("calls onLoadPrompt with tool content", async () => {
    const onLoadPrompt = vi.fn()
    render(<ToolBuilder {...makeProps({ onLoadPrompt })} />)
    await userEvent.type(screen.getByPlaceholderText("ex: repo-summarizer"), "my-tool")
    await userEvent.click(screen.getByRole("button", { name: /Load into prompt/i }))
    expect(onLoadPrompt).toHaveBeenCalledTimes(1)
    expect(onLoadPrompt.mock.calls[0][0]).toContain("my-tool")
    expect(onLoadPrompt.mock.calls[0][0]).toContain("Create a new PhpBot tool")
  })

  it("calls onAppendPrompt when Append clicked", async () => {
    const onAppendPrompt = vi.fn()
    render(<ToolBuilder {...makeProps({ onAppendPrompt })} />)
    await userEvent.click(screen.getByRole("button", { name: /Append to prompt/i }))
    expect(onAppendPrompt).toHaveBeenCalledTimes(1)
  })

  it("prompt includes storage/tools reference", async () => {
    const onLoadPrompt = vi.fn()
    render(<ToolBuilder {...makeProps({ onLoadPrompt })} />)
    await userEvent.click(screen.getByRole("button", { name: /Load into prompt/i }))
    expect(onLoadPrompt.mock.calls[0][0]).toContain("storage/tools")
  })
})
