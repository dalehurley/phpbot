import type { BotRun } from "@/types/bot";

type RunLogProps = {
  runs: BotRun[];
  activeRunId: string | null;
  onSelectRun: (id: string) => void;
};

export function RunLog({ runs, activeRunId, onSelectRun }: RunLogProps) {
  return (
    <section className="panel space-y-6">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold">Run Log</h2>
        <span className="text-sm text-muted-foreground">
          {runs.length} recent
        </span>
      </div>

      <div className="space-y-4">
        {runs.length === 0 ? (
          <div className="empty-state">
            <p className="text-sm text-muted-foreground">
              Run a prompt to see results here.
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {runs.map((run) => (
              <button
                key={run.id}
                type="button"
                className={`run-card ${
                  run.id === activeRunId ? "run-card-active" : ""
                }`}
                onClick={() => onSelectRun(run.id)}
              >
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium">{run.prompt}</span>
                  <span className="text-xs text-muted-foreground">
                    {run.startedAt}
                  </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                  {run.response ? (
                    <>
                      <span className="chip">Success</span>
                      <span className="chip">
                        {run.response.iterations} iterations
                      </span>
                      <span className="chip">
                        {run.response.tool_calls.length} tool calls
                      </span>
                    </>
                  ) : run.failed ? (
                    <span className="chip chip-error">Failed</span>
                  ) : (
                    <span className="chip chip-live">Running</span>
                  )}
                </div>
              </button>
            ))}
          </div>
        )}
      </div>
    </section>
  );
}
