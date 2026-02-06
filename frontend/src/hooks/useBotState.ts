import { useEffect, useMemo, useRef, useState } from "react";
import type { BotRun, BotResponse, Overrides } from "@/types/bot";
import { defaultOverrides, API_PATH } from "@/types/bot";

export function useBotState() {
  const [prompt, setPrompt] = useState("");
  const [isRunning, setIsRunning] = useState(false);
  const [verbose, setVerbose] = useState(false);
  const [runs, setRuns] = useState<BotRun[]>([]);
  const [activeRunId, setActiveRunId] = useState<string | null>(null);
  const [advancedOpen, setAdvancedOpen] = useState(true);
  const [overrides, setOverrides] = useState<Overrides>({ ...defaultOverrides });
  const [logLoading, setLogLoading] = useState(false);
  const wsRef = useRef<WebSocket | null>(null);
  const pendingSubscriptions = useRef<Set<string>>(new Set());

  const activeRun = useMemo(
    () => runs.find((run) => run.id === activeRunId) ?? null,
    [runs, activeRunId]
  );

  const payloadPreview = useMemo(() => {
    const base = {
      prompt: prompt.trim(),
      verbose,
    };

    if (!advancedOpen) {
      return base;
    }

    return {
      ...base,
      overrides,
    };
  }, [prompt, verbose, overrides, advancedOpen]);

  const runBot = async () => {
    if (!prompt.trim() || isRunning) return;

    const id = crypto.randomUUID();
    const newRun: BotRun = {
      id,
      prompt: prompt.trim(),
      startedAt: new Date().toLocaleString(),
      payload: {
        ...payloadPreview,
        client_run_id: id,
      },
      liveProgress: [],
    };

    setRuns((prev) => [newRun, ...prev].slice(0, 8));
    setActiveRunId(id);
    setIsRunning(true);

    try {
      pendingSubscriptions.current.add(id);
      if (wsRef.current?.readyState === WebSocket.OPEN) {
        wsRef.current.send(
          JSON.stringify({
            type: "subscribe",
            run_id: id,
          }),
        );
        pendingSubscriptions.current.delete(id);
      }

      const response = await fetch(API_PATH, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(newRun.payload),
      });

      if (!response.ok) {
        const text = await response.text();
        throw new Error(text || `Request failed (${response.status})`);
      }

      const data = (await response.json()) as BotResponse;
      setRuns((prev) =>
        prev.map((run) => (run.id === id ? { ...run, response: data } : run))
      );
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "Unexpected error";
      setRuns((prev) =>
        prev.map((run) => (run.id === id ? { ...run, failed: message } : run))
      );
    } finally {
      setIsRunning(false);
    }
  };

  const clearOutput = () => {
    setRuns([]);
    setActiveRunId(null);
  };

  const fetchLogs = async () => {
    if (!activeRun?.response?.log_id || logLoading) return;
    setLogLoading(true);
    try {
      const response = await fetch(`/api/logs.php?id=${activeRun.response.log_id}`);
      if (!response.ok) {
        const text = await response.text();
        throw new Error(text || `Log request failed (${response.status})`);
      }
      const data = (await response.json()) as { content?: string };
      setRuns((prev) =>
        prev.map((run) =>
          run.id === activeRun.id
            ? { ...run, logContent: data.content ?? "" }
            : run
        )
      );
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "Failed to load logs";
      setRuns((prev) =>
        prev.map((run) =>
          run.id === activeRun.id ? { ...run, logContent: message } : run
        )
      );
    } finally {
      setLogLoading(false);
    }
  };

  const updateOverride = (
    key: keyof Overrides,
    value: string | number
  ) => {
    setOverrides((prev) => ({
      ...prev,
      [key]: value,
    }));
  };

  const resetOverrides = () => {
    setOverrides({ ...defaultOverrides });
  };

  useEffect(() => {
    const url =
      import.meta.env.VITE_WS_URL ||
      `ws://${window.location.hostname}:8788`;

    const ws = new WebSocket(url);
    wsRef.current = ws;

    ws.onopen = () => {
      pendingSubscriptions.current.forEach((runId) => {
        ws.send(
          JSON.stringify({
            type: "subscribe",
            run_id: runId,
          }),
        );
      });
      pendingSubscriptions.current.clear();
    };

    ws.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data) as {
          run_id?: string;
          type?: string;
          stage?: string;
          message?: string;
          ts?: number;
        };
        if (!data.run_id || data.type !== "progress") {
          return;
        }
        setRuns((prev) =>
          prev.map((run) =>
            run.id === data.run_id
              ? {
                  ...run,
                  liveProgress: [
                    ...(run.liveProgress ?? []),
                    {
                      stage: data.stage ?? "update",
                      message: data.message ?? "",
                      ts: data.ts ?? Date.now() / 1000,
                    },
                  ],
                }
              : run,
          ),
        );
      } catch {
        return;
      }
    };

    ws.onerror = () => {
      // Swallow errors to keep UI responsive; logs are still available via HTTP.
    };

    return () => {
      ws.close();
    };
  }, []);

  return {
    prompt,
    setPrompt,
    isRunning,
    verbose,
    setVerbose,
    runs,
    activeRunId,
    setActiveRunId,
    activeRun,
    advancedOpen,
    setAdvancedOpen,
    overrides,
    updateOverride,
    resetOverrides,
    payloadPreview,
    logLoading,
    runBot,
    clearOutput,
    fetchLogs,
  };
}
