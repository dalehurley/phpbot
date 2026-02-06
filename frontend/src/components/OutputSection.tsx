import { useEffect, useRef } from "react";
import type { BotRun } from "@/types/bot";

type OutputSectionProps = {
  activeRun: BotRun | null;
  logLoading: boolean;
  onFetchLogs: () => void;
};

export function OutputSection({
  activeRun,
  logLoading,
  onFetchLogs,
}: OutputSectionProps) {
  const liveProgressRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!liveProgressRef.current) return;
    liveProgressRef.current.scrollTop = liveProgressRef.current.scrollHeight;
  }, [activeRun?.liveProgress?.length]);

  return (
    <section className="panel panel-dark space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h2 className="text-xl font-semibold text-white">Output</h2>
        <div className="flex flex-wrap gap-2 text-xs text-white/60">
          {activeRun?.response?.token_usage &&
            Object.entries(activeRun.response.token_usage).map(
              ([key, value]) => (
                <span key={key} className="chip chip-light">
                  {key}: {value}
                </span>
              ),
            )}
        </div>
      </div>

      {!activeRun ? (
        <div className="empty-state text-white/70">
          <p className="text-sm">Select a run to view details.</p>
        </div>
      ) : activeRun.failed ? (
        <div className="space-y-3">
          <p className="text-sm text-red-200">{activeRun.failed}</p>
        </div>
          ) : !activeRun.response ? (
        <div className="space-y-4">
          <p className="text-sm text-white/70">Running…</p>
          <div className="terminal">
            <p className="terminal-title">Live progress</p>
            <div className="terminal-body space-y-3" ref={liveProgressRef}>
              {activeRun.liveProgress?.length ? (
                activeRun.liveProgress.map((entry, index) => (
                  <div key={`${entry.stage}-${index}`}>
                    <p className="text-xs uppercase tracking-[0.18em] text-white/50">
                      {entry.stage}
                    </p>
                    <p className="text-sm text-white/80">{entry.message}</p>
                  </div>
                ))
              ) : (
                <p className="text-sm text-white/60">
                  Waiting for progress updates…
                </p>
              )}
            </div>
          </div>
        </div>
      ) : (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <div className="space-y-4">
            <div className="terminal">
              <p className="terminal-title">Answer</p>
              <pre className="terminal-body">
                {activeRun.response.answer || "(No answer returned)"}
              </pre>
            </div>
            {activeRun.response.error && (
              <div className="terminal terminal-error">
                <p className="terminal-title">Error</p>
                <pre className="terminal-body">{activeRun.response.error}</pre>
              </div>
            )}
            {activeRun.response.log_tail && (
              <div className="terminal">
                <p className="terminal-title">Server log (tail)</p>
                <pre className="terminal-body">
                  {activeRun.response.log_tail}
                </pre>
              </div>
            )}
          </div>

          <div className="space-y-4">
            <div className="terminal">
              <p className="terminal-title">Progress</p>
              <div className="terminal-body space-y-3">
                {activeRun.response.progress?.length ? (
                  activeRun.response.progress.map((entry, index) => (
                    <div key={`${entry.stage}-${index}`}>
                      <p className="text-xs uppercase tracking-[0.18em] text-white/50">
                        {entry.stage}
                      </p>
                      <p className="text-sm text-white/80">{entry.message}</p>
                    </div>
                  ))
                ) : (
                  <p className="text-sm text-white/60">
                    No progress updates returned.
                  </p>
                )}
              </div>
            </div>
            <div className="terminal">
              <p className="terminal-title">Analysis</p>
              <pre className="terminal-body">
                {JSON.stringify(activeRun.response.analysis, null, 2)}
              </pre>
            </div>
            {activeRun.response.overrides_applied && (
              <div className="terminal">
                <p className="terminal-title">Overrides applied</p>
                <pre className="terminal-body">
                  {JSON.stringify(
                    activeRun.response.overrides_applied,
                    null,
                    2,
                  )}
                </pre>
              </div>
            )}
            {activeRun.response.log_id && (
              <div className="terminal">
                <div className="flex items-center justify-between">
                  <p className="terminal-title">Server log (full)</p>
                  <button
                    type="button"
                    className="btn-ghost"
                    onClick={onFetchLogs}
                    disabled={logLoading}
                  >
                    {logLoading ? "Loading…" : "Refresh"}
                  </button>
                </div>
                <pre className="terminal-body">
                  {activeRun.logContent
                    ? activeRun.logContent
                    : "Use refresh to load full logs."}
                </pre>
              </div>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
