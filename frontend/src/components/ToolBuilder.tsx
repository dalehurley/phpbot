import { useState } from "react";
import { Button } from "@/components/ui/button";

type ToolBuilderProps = {
  onLoadPrompt: (prompt: string) => void;
  onAppendPrompt: (prompt: string) => void;
};

export function ToolBuilder({
  onLoadPrompt,
  onAppendPrompt,
}: ToolBuilderProps) {
  const [toolName, setToolName] = useState("");
  const [toolDescription, setToolDescription] = useState("");
  const [toolPurpose, setToolPurpose] = useState("");
  const [toolInputs, setToolInputs] = useState("");
  const [toolOutputs, setToolOutputs] = useState("");
  const [toolNotes, setToolNotes] = useState("");

  const buildToolPrompt = () => {
    const parts = [
      "Create a new PhpBot tool with the following details.",
      toolName ? `Name: ${toolName}` : "",
      toolDescription ? `Short description: ${toolDescription}` : "",
      toolPurpose ? `Purpose and behavior: ${toolPurpose}` : "",
      toolInputs ? `Inputs/parameters: ${toolInputs}` : "",
      toolOutputs ? `Outputs/return format: ${toolOutputs}` : "",
      toolNotes ? `Constraints/notes: ${toolNotes}` : "",
      "Implement the tool so it can be persisted in storage/tools and promoted when needed.",
      "If a script is required, create it under storage/tools or scripts as appropriate.",
      "Summarize where files were created and how to invoke the tool.",
    ].filter(Boolean);

    return parts.join("\n");
  };

  const handleLoad = () => {
    onLoadPrompt(buildToolPrompt());
  };

  const handleAppend = () => {
    onAppendPrompt(buildToolPrompt());
  };

  return (
    <section className="panel space-y-6">
      <div className="space-y-2">
        <h2 className="text-xl font-semibold">Tool Builder</h2>
        <p className="text-sm text-muted-foreground">
          Use AI to design and persist new tools. These become reusable
          capabilities for future runs.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <label className="field">
          <span className="field-label">Tool name</span>
          <input
            className="input-surface"
            placeholder="ex: repo-summarizer"
            value={toolName}
            onChange={(event) => setToolName(event.target.value)}
          />
        </label>
        <label className="field">
          <span className="field-label">Short description</span>
          <input
            className="input-surface"
            placeholder="One sentence summary"
            value={toolDescription}
            onChange={(event) => setToolDescription(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">Purpose and behavior</span>
          <input
            className="input-surface"
            placeholder="What should the tool do, and when is it used?"
            value={toolPurpose}
            onChange={(event) => setToolPurpose(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">Inputs / parameters</span>
          <input
            className="input-surface"
            placeholder="List params, types, defaults"
            value={toolInputs}
            onChange={(event) => setToolInputs(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">Outputs</span>
          <input
            className="input-surface"
            placeholder="Return payload format, files created, etc."
            value={toolOutputs}
            onChange={(event) => setToolOutputs(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">Constraints</span>
          <input
            className="input-surface"
            placeholder="Safety, allowed commands, edge cases"
            value={toolNotes}
            onChange={(event) => setToolNotes(event.target.value)}
          />
        </label>
      </div>

      <div className="flex flex-wrap gap-3">
        <Button className="btn-primary" onClick={handleLoad}>
          Load into prompt
        </Button>
        <button type="button" className="btn-ghost" onClick={handleAppend}>
          Append to prompt
        </button>
      </div>
    </section>
  );
}
