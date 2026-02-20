import { describe, it, expect, vi, beforeEach } from "vitest"
import { renderHook, act } from "@testing-library/react"
import { useBotState } from "@/hooks/useBotState"
import { defaultOverrides } from "@/types/bot"

class MockWebSocket {
  static OPEN = 1
  readyState = MockWebSocket.OPEN
  onopen: (() => void) | null = null
  onmessage: ((e: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  send = vi.fn()
  close = vi.fn()
  constructor() { setTimeout(() => this.onopen?.(), 0) }
}
global.WebSocket = MockWebSocket as unknown as typeof WebSocket

const mockFetch = vi.fn()
global.fetch = mockFetch

vi.spyOn(global.crypto, "randomUUID").mockReturnValue("test-uuid-123" as ReturnType<typeof crypto.randomUUID>)

describe("useBotState", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetch.mockReset()
  })

  it("initializes with defaults", () => {
    const { result } = renderHook(() => useBotState())
    expect(result.current.prompt).toBe("")
    expect(result.current.isRunning).toBe(false)
    expect(result.current.runs).toEqual([])
    expect(result.current.activeRunId).toBeNull()
    expect(result.current.overrides).toEqual(defaultOverrides)
  })

  it("setPrompt updates prompt", () => {
    const { result } = renderHook(() => useBotState())
    act(() => { result.current.setPrompt("hello") })
    expect(result.current.prompt).toBe("hello")
  })

  it("setVerbose updates verbose", () => {
    const { result } = renderHook(() => useBotState())
    act(() => { result.current.setVerbose(true) })
    expect(result.current.verbose).toBe(true)
  })

  it("clearOutput resets runs and activeRunId", () => {
    const { result } = renderHook(() => useBotState())
    act(() => { result.current.setActiveRunId("r1") })
    act(() => { result.current.clearOutput() })
    expect(result.current.runs).toEqual([])
    expect(result.current.activeRunId).toBeNull()
  })

  it("updateOverride updates a key", () => {
    const { result } = renderHook(() => useBotState())
    act(() => { result.current.updateOverride("model", "new-model") })
    expect(result.current.overrides.model).toBe("new-model")
  })

  it("resetOverrides resets to defaults", () => {
    const { result } = renderHook(() => useBotState())
    act(() => { result.current.updateOverride("model", "changed") })
    act(() => { result.current.resetOverrides() })
    expect(result.current.overrides).toEqual(defaultOverrides)
  })

  it("payloadPreview includes overrides when advancedOpen", () => {
    const { result } = renderHook(() => useBotState())
    expect(result.current.payloadPreview).toHaveProperty("overrides")
  })

  it("payloadPreview excludes overrides when advancedOpen=false", () => {
    const { result } = renderHook(() => useBotState())
    act(() => { result.current.setAdvancedOpen(false) })
    expect(result.current.payloadPreview).not.toHaveProperty("overrides")
  })

  it("runBot does nothing when prompt is empty", async () => {
    const { result } = renderHook(() => useBotState())
    await act(async () => { await result.current.runBot() })
    expect(result.current.runs).toHaveLength(0)
    expect(mockFetch).not.toHaveBeenCalled()
  })

  it("runBot creates run and calls fetch", async () => {
    const { result } = renderHook(() => useBotState())
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ success: true, answer: "Done!", error: null, iterations: 1, tool_calls: [], token_usage: {}, analysis: {} }),
    })
    await act(async () => { result.current.setPrompt("List files") })
    await act(async () => { await result.current.runBot() })
    expect(result.current.runs).toHaveLength(1)
    expect(mockFetch).toHaveBeenCalledWith("/api/run.php", expect.any(Object))
  })

  it("runBot sets failed on fetch error", async () => {
    const { result } = renderHook(() => useBotState())
    mockFetch.mockResolvedValueOnce({ ok: false, status: 500, text: async () => "Server error" })
    await act(async () => { result.current.setPrompt("Fail") })
    await act(async () => { await result.current.runBot() })
    expect(result.current.runs[0].failed).toBeTruthy()
  })

  it("runBot handles network error", async () => {
    const { result } = renderHook(() => useBotState())
    mockFetch.mockRejectedValueOnce(new Error("Network error"))
    await act(async () => { result.current.setPrompt("Fail") })
    await act(async () => { await result.current.runBot() })
    expect(result.current.runs[0].failed).toBe("Network error")
  })

  it("fetchLogs does nothing when no active run", async () => {
    const { result } = renderHook(() => useBotState())
    await act(async () => { await result.current.fetchLogs() })
    expect(mockFetch).not.toHaveBeenCalled()
  })

  it("activeRun returns the active run after runBot", async () => {
    const { result } = renderHook(() => useBotState())
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ success: true, answer: "done", error: null, iterations: 1, tool_calls: [], token_usage: {}, analysis: {} }),
    })
    await act(async () => { result.current.setPrompt("Active test") })
    await act(async () => { await result.current.runBot() })
    expect(result.current.activeRun).not.toBeNull()
    expect(result.current.activeRun?.prompt).toBe("Active test")
  })
})
