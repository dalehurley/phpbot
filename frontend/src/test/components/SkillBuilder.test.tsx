import { describe, it, expect, vi } from "vitest"
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { SkillBuilder } from "@/components/SkillBuilder"

function makeProps(o = {}) {
  return { onLoadPrompt: vi.fn(), onAppendPrompt: vi.fn(), ...o }
}

describe("SkillBuilder", () => {
  it("renders heading", () => {
    render(<SkillBuilder {...makeProps()} />)
    expect(screen.getByText("Skill Builder")).toBeInTheDocument()
  })

  it("renders all fields", () => {
    render(<SkillBuilder {...makeProps()} />)
    expect(screen.getByText("Skill name")).toBeInTheDocument()
    expect(screen.getByText("Short description")).toBeInTheDocument()
    expect(screen.getByText("Primary goal")).toBeInTheDocument()
    expect(screen.getByText("Key use cases")).toBeInTheDocument()
    expect(screen.getByText("References")).toBeInTheDocument()
    expect(screen.getByText("Scripts")).toBeInTheDocument()
    expect(screen.getByText("Assets")).toBeInTheDocument()
    expect(screen.getByText("Constraints")).toBeInTheDocument()
  })

  it("calls onLoadPrompt with skill content", async () => {
    const onLoadPrompt = vi.fn()
    render(<SkillBuilder {...makeProps({ onLoadPrompt })} />)
    await userEvent.type(screen.getByPlaceholderText("ex: repo-audit"), "my-skill")
    await userEvent.click(screen.getByRole("button", { name: /Load into prompt/i }))
    expect(onLoadPrompt).toHaveBeenCalledTimes(1)
    expect(onLoadPrompt.mock.calls[0][0]).toContain("my-skill")
    expect(onLoadPrompt.mock.calls[0][0]).toContain("Create a new PhpBot skill")
  })

  it("calls onAppendPrompt when Append clicked", async () => {
    const onAppendPrompt = vi.fn()
    render(<SkillBuilder {...makeProps({ onAppendPrompt })} />)
    await userEvent.click(screen.getByRole("button", { name: /Append to prompt/i }))
    expect(onAppendPrompt).toHaveBeenCalledTimes(1)
  })

  it("prompt includes SKILL.md reference", async () => {
    const onLoadPrompt = vi.fn()
    render(<SkillBuilder {...makeProps({ onLoadPrompt })} />)
    await userEvent.click(screen.getByRole("button", { name: /Load into prompt/i }))
    expect(onLoadPrompt.mock.calls[0][0]).toContain("SKILL.md")
  })
})
