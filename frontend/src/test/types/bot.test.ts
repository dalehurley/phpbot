import { describe, it, expect } from "vitest"
import { defaultOverrides, API_PATH, examplePrompts } from "@/types/bot"
import type { BotProgress, BotResponse, BotRun, Overrides } from "@/types/bot"

describe("bot types and constants", () => {
  it("API_PATH is correct", () => {
    expect(API_PATH).toBe("/api/run.php")
  })

  it("defaultOverrides has all required fields", () => {
    expect(defaultOverrides).toHaveProperty("model")
    expect(defaultOverrides).toHaveProperty("fast_model")
    expect(defaultOverrides).toHaveProperty("super_model")
    expect(defaultOverrides).toHaveProperty("max_iterations")
    expect(defaultOverrides).toHaveProperty("max_tokens")
    expect(defaultOverrides).toHaveProperty("temperature")
    expect(defaultOverrides).toHaveProperty("timeout")
  })

  it("defaultOverrides has correct default values", () => {
    expect(defaultOverrides.model).toBe("claude-sonnet-4-5")
    expect(defaultOverrides.fast_model).toBe("claude-haiku-4-5")
    expect(defaultOverrides.super_model).toBe("claude-opus-4-5")
    expect(defaultOverrides.max_iterations).toBe(20)
    expect(defaultOverrides.max_tokens).toBe(4096)
    expect(defaultOverrides.temperature).toBe(0.7)
    expect(defaultOverrides.timeout).toBe(120)
  })

  it("examplePrompts is a non-empty array of strings", () => {
    expect(Array.isArray(examplePrompts)).toBe(true)
    expect(examplePrompts.length).toBeGreaterThan(0)
    examplePrompts.forEach((p) => {
      expect(typeof p).toBe("string")
      expect(p.length).toBeGreaterThan(0)
    })
  })

  it("BotProgress type shape", () => {
    const progress: BotProgress = { stage: "running", message: "Processing…", ts: 1000 }
    expect(progress.stage).toBe("running")
    expect(typeof progress.ts).toBe("number")
  })

  it("BotResponse type shape", () => {
    const response: BotResponse = {
      success: true,
      answer: "Done!",
      error: null,
      iterations: 3,
      tool_calls: [],
      token_usage: { total: 100 },
      analysis: {},
    }
    expect(response.success).toBe(true)
    expect(response.iterations).toBe(3)
  })

  it("BotRun type shape", () => {
    const run: BotRun = { id: "abc-123", prompt: "Hello", startedAt: "now" }
    expect(run.id).toBe("abc-123")
  })

  it("Overrides type assignable from defaultOverrides", () => {
    const overrides: Overrides = { ...defaultOverrides }
    expect(overrides.model).toBe("claude-sonnet-4-5")
  })
})
