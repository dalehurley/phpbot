import { describe, it, expect } from "vitest"
import { cn } from "@/lib/utils"

describe("cn utility", () => {
  it("merges class names", () => {
    expect(cn("foo", "bar")).toBe("foo bar")
  })

  it("handles conditional classes", () => {
    expect(cn("foo", false && "bar", "baz")).toBe("foo baz")
    expect(cn("foo", true && "bar", "baz")).toBe("foo bar baz")
  })

  it("handles undefined and null", () => {
    expect(cn("foo", undefined, null, "bar")).toBe("foo bar")
  })

  it("handles empty input", () => {
    expect(cn()).toBe("")
  })

  it("merges tailwind conflicting classes", () => {
    const result = cn("px-2", "px-4")
    expect(result).toBe("px-4")
  })

  it("handles arrays of classes", () => {
    expect(cn(["foo", "bar"])).toBe("foo bar")
  })

  it("handles object class map", () => {
    expect(cn({ foo: true, bar: false, baz: true })).toBe("foo baz")
  })
})
