import { Button } from "@/components/ui/button";
import { examplePrompts } from "@/types/bot";

type PromptSectionProps = {
  prompt: string;
  setPrompt: (value: string) => void;
  isRunning: boolean;
  verbose: boolean;
  setVerbose: (value: boolean) => void;
  onRun: () => void;
  onClear: () => void;
  hasRuns: boolean;
};

export function PromptSection({
  prompt,
  setPrompt,
  isRunning,
  verbose,
  setVerbose,
  onRun,
  onClear,
  hasRuns,
}: PromptSectionProps) {
  return (
    <section className="panel space-y-6">
      <div className="space-y-2">
        <h2 className="text-xl font-semibold">Prompt</h2>
        <p className="text-sm text-muted-foreground">
          Describe the task with as much context as you need. Use
          natural-language commands; PhpBot will select the right agent.
        </p>
      </div>

      <div className="space-y-4">
        <textarea
          className="input-surface min-h-[180px] w-full resize-none"
          placeholder="Example: Scan the repo and surface any unused tools."
          value={prompt}
          onChange={(event) => setPrompt(event.target.value)}
          onKeyDown={(event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === "Enter") {
              event.preventDefault();
              onRun();
            }
          }}
        />

        <div className="flex flex-wrap items-center gap-3">
          <Button
            className="btn-primary"
            onClick={onRun}
            disabled={!prompt.trim() || isRunning}
          >
            {isRunning ? "Running…" : "Run bot"}
          </Button>
          <button
            type="button"
            className="btn-ghost"
            onClick={onClear}
            disabled={isRunning && !hasRuns}
          >
            Clear
          </button>
          <label className="flex items-center gap-2 text-sm text-muted-foreground">
            <input
              type="checkbox"
              className="checkbox"
              checked={verbose}
              onChange={(event) => setVerbose(event.target.checked)}
            />
            Verbose
          </label>
        </div>

        <div className="space-y-2">
          <p className="text-xs uppercase tracking-[0.2em] text-muted-foreground">
            Quick starts
          </p>
          <div className="flex flex-wrap gap-2">
            {examplePrompts.map((item) => (
              <button
                key={item}
                type="button"
                className="chip chip-action"
                onClick={() => setPrompt(item)}
              >
                {item}
              </button>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
