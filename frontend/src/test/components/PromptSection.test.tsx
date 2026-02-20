import { describe, it, expect, vi } from "vitest"
import { render, screen, fireEvent } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { PromptSection } from "@/components/PromptSection"
import { examplePrompts } from "@/types/bot"

function makeProps(overrides = {}) {
  return {
    prompt: "",
    setPrompt: vi.fn(),
    isRunning: false,
    verbose: false,
    setVerbose: vi.fn(),
    onRun: vi.fn(),
    onClear: vi.fn(),
    hasRuns: false,
    ...overrides,
  }
}

describe("PromptSection", () => {
  it("renders Prompt heading", () => {
    render(<PromptSection {...makeProps()} />)
    expect(screen.getByText("Prompt")).toBeInTheDocument()
  })

  it("shows textarea placeholder", () => {
    render(<PromptSection {...makeProps()} />)
    expect(screen.getByPlaceholderText(/Scan the repo/)).toBeInTheDocument()
  })

  it("shows current prompt value", () => {
    render(<PromptSection {...makeProps({ prompt: "Test" })} />)
    expect((screen.getByRole("textbox") as HTMLTextAreaElement).value).toBe("Test")
  })

  it("calls setPrompt on input", async () => {
    const setPrompt = vi.fn()
    render(<PromptSection {...makeProps({ setPrompt })} />)
    await userEvent.type(screen.getByRole("textbox"), "Hi")
    expect(setPrompt).toHaveBeenCalled()
  })

  it("shows Run bot button disabled when empty", () => {
    render(<PromptSection {...makeProps({ prompt: "" })} />)
    expect(screen.getByRole("button", { name: /Run bot/i })).toBeDisabled()
  })

  it("enables Run bot when prompt non-empty", () => {
    render(<PromptSection {...makeProps({ prompt: "hello" })} />)
    expect(screen.getByRole("button", { name: /Run bot/i })).not.toBeDisabled()
  })

  it("shows Running… when isRunning", () => {
    render(<PromptSection {...makeProps({ isRunning: true })} />)
    expect(screen.getByText("Running…")).toBeInTheDocument()
  })

  it("calls onRun when Run bot clicked", async () => {
    const onRun = vi.fn()
    render(<PromptSection {...makeProps({ prompt: "hello", onRun })} />)
    await userEvent.click(screen.getByRole("button", { name: /Run bot/i }))
    expect(onRun).toHaveBeenCalledTimes(1)
  })

  it("calls onClear when Clear clicked", async () => {
    const onClear = vi.fn()
    render(<PromptSection {...makeProps({ onClear })} />)
    await userEvent.click(screen.getByRole("button", { name: /Clear/i }))
    expect(onClear).toHaveBeenCalledTimes(1)
  })

  it("verbose checkbox reflects state", () => {
    render(<PromptSection {...makeProps({ verbose: true })} />)
    expect(screen.getByRole("checkbox")).toBeChecked()
  })

  it("calls setVerbose on checkbox change", async () => {
    const setVerbose = vi.fn()
    render(<PromptSection {...makeProps({ setVerbose })} />)
    await userEvent.click(screen.getByRole("checkbox"))
    expect(setVerbose).toHaveBeenCalledWith(true)
  })

  it("renders example prompts", () => {
    render(<PromptSection {...makeProps()} />)
    expect(screen.getByText(examplePrompts[0])).toBeInTheDocument()
  })

  it("calls setPrompt with example on click", async () => {
    const setPrompt = vi.fn()
    render(<PromptSection {...makeProps({ setPrompt })} />)
    await userEvent.click(screen.getByText(examplePrompts[0]))
    expect(setPrompt).toHaveBeenCalledWith(examplePrompts[0])
  })

  it("triggers onRun on Cmd+Enter", () => {
    const onRun = vi.fn()
    render(<PromptSection {...makeProps({ prompt: "hi", onRun })} />)
    fireEvent.keyDown(screen.getByRole("textbox"), { key: "Enter", metaKey: true })
    expect(onRun).toHaveBeenCalledTimes(1)
  })

  it("triggers onRun on Ctrl+Enter", () => {
    const onRun = vi.fn()
    render(<PromptSection {...makeProps({ prompt: "hi", onRun })} />)
    fireEvent.keyDown(screen.getByRole("textbox"), { key: "Enter", ctrlKey: true })
    expect(onRun).toHaveBeenCalledTimes(1)
  })
})
