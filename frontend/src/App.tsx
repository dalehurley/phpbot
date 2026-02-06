import { useBotState } from "@/hooks/useBotState";
import { Header } from "@/components/Header";
import { PromptSection } from "@/components/PromptSection";
import { RunLog } from "@/components/RunLog";
import { SkillBuilder } from "@/components/SkillBuilder";
import { ToolBuilder } from "@/components/ToolBuilder";
import { AdvancedControls } from "@/components/AdvancedControls";
import { OutputSection } from "@/components/OutputSection";

function App() {
  const {
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
  } = useBotState();

  const handleLoadPrompt = (newPrompt: string) => {
    setPrompt(newPrompt);
  };

  const handleAppendPrompt = (payload: string) => {
    setPrompt((prev) => (prev.trim() ? `${prev}\n\n${payload}` : payload));
  };

  return (
    <div className="min-h-svh px-6 py-10 lg:px-12">
      <div className="mx-auto max-w-6xl space-y-10">
        <Header isRunning={isRunning} activeRun={activeRun} />

        <main className="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
          <PromptSection
            prompt={prompt}
            setPrompt={setPrompt}
            isRunning={isRunning}
            verbose={verbose}
            setVerbose={setVerbose}
            onRun={runBot}
            onClear={clearOutput}
            hasRuns={runs.length > 0}
          />

          <RunLog
            runs={runs}
            activeRunId={activeRunId}
            onSelectRun={setActiveRunId}
          />
        </main>

        <OutputSection
          activeRun={activeRun}
          logLoading={logLoading}
          onFetchLogs={fetchLogs}
        />

        <SkillBuilder
          onLoadPrompt={handleLoadPrompt}
          onAppendPrompt={handleAppendPrompt}
        />

        <ToolBuilder
          onLoadPrompt={handleLoadPrompt}
          onAppendPrompt={handleAppendPrompt}
        />

        <AdvancedControls
          isOpen={advancedOpen}
          onToggle={() => setAdvancedOpen((prev) => !prev)}
          overrides={overrides}
          onUpdateOverride={updateOverride}
          onReset={resetOverrides}
          payloadPreview={payloadPreview}
        />
      </div>
    </div>
  );
}

export default App;
