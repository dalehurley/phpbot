import type { Overrides } from "@/types/bot";

type AdvancedControlsProps = {
  isOpen: boolean;
  onToggle: () => void;
  overrides: Overrides;
  onUpdateOverride: (key: keyof Overrides, value: string | number) => void;
  onReset: () => void;
  payloadPreview: Record<string, unknown>;
};

export function AdvancedControls({
  isOpen,
  onToggle,
  overrides,
  onUpdateOverride,
  onReset,
  payloadPreview,
}: AdvancedControlsProps) {
  return (
    <section className="panel space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold">Advanced controls</h2>
          <p className="text-sm text-muted-foreground">
            Override model routing and budgets for this run.
          </p>
        </div>
        <button type="button" className="btn-ghost" onClick={onToggle}>
          {isOpen ? "Collapse" : "Expand"}
        </button>
      </div>

      {isOpen && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <div className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <label className="field">
                <span className="field-label">Model</span>
                <input
                  className="input-surface"
                  value={overrides.model}
                  onChange={(event) =>
                    onUpdateOverride("model", event.target.value)
                  }
                />
              </label>
              <label className="field">
                <span className="field-label">Fast model</span>
                <input
                  className="input-surface"
                  value={overrides.fast_model}
                  onChange={(event) =>
                    onUpdateOverride("fast_model", event.target.value)
                  }
                />
              </label>
              <label className="field">
                <span className="field-label">Super model</span>
                <input
                  className="input-surface"
                  value={overrides.super_model}
                  onChange={(event) =>
                    onUpdateOverride("super_model", event.target.value)
                  }
                />
              </label>
              <label className="field">
                <span className="field-label">Max iterations</span>
                <input
                  type="number"
                  min={1}
                  className="input-surface"
                  value={overrides.max_iterations}
                  onChange={(event) =>
                    onUpdateOverride(
                      "max_iterations",
                      Number(event.target.value),
                    )
                  }
                />
              </label>
              <label className="field">
                <span className="field-label">Max tokens</span>
                <input
                  type="number"
                  min={256}
                  step={256}
                  className="input-surface"
                  value={overrides.max_tokens}
                  onChange={(event) =>
                    onUpdateOverride("max_tokens", Number(event.target.value))
                  }
                />
              </label>
              <label className="field">
                <span className="field-label">Temperature</span>
                <input
                  type="number"
                  min={0}
                  max={2}
                  step={0.1}
                  className="input-surface"
                  value={overrides.temperature}
                  onChange={(event) =>
                    onUpdateOverride("temperature", Number(event.target.value))
                  }
                />
              </label>
              <label className="field">
                <span className="field-label">Timeout (seconds)</span>
                <input
                  type="number"
                  min={10}
                  step={10}
                  className="input-surface"
                  value={overrides.timeout}
                  onChange={(event) =>
                    onUpdateOverride("timeout", Number(event.target.value))
                  }
                />
              </label>
            </div>
            <div className="flex flex-wrap gap-2">
              <button type="button" className="btn-ghost" onClick={onReset}>
                Reset defaults
              </button>
            </div>
          </div>
          <div className="terminal">
            <p className="terminal-title">Payload preview</p>
            <pre className="terminal-body">
              {JSON.stringify(payloadPreview, null, 2)}
            </pre>
          </div>
        </div>
      )}
    </section>
  );
}
