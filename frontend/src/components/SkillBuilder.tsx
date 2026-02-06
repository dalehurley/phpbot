import { useState } from "react";
import { Button } from "@/components/ui/button";

type SkillBuilderProps = {
  onLoadPrompt: (prompt: string) => void;
  onAppendPrompt: (prompt: string) => void;
};

export function SkillBuilder({
  onLoadPrompt,
  onAppendPrompt,
}: SkillBuilderProps) {
  const [skillName, setSkillName] = useState("");
  const [skillDescription, setSkillDescription] = useState("");
  const [skillGoal, setSkillGoal] = useState("");
  const [skillReferences, setSkillReferences] = useState("");
  const [skillScripts, setSkillScripts] = useState("");
  const [skillAssets, setSkillAssets] = useState("");
  const [skillUseCases, setSkillUseCases] = useState("");
  const [skillNotes, setSkillNotes] = useState("");

  const buildSkillPrompt = () => {
    const parts = [
      "Create a new PhpBot skill with the following details.",
      skillName ? `Name: ${skillName}` : "",
      skillDescription ? `Short description: ${skillDescription}` : "",
      skillGoal ? `Primary goal: ${skillGoal}` : "",
      skillUseCases ? `Key use cases: ${skillUseCases}` : "",
      skillReferences
        ? `Reference files to include or author: ${skillReferences}`
        : "",
      skillScripts ? `Scripts to create: ${skillScripts}` : "",
      skillAssets ? `Assets/templates to include: ${skillAssets}` : "",
      skillNotes ? `Constraints/notes: ${skillNotes}` : "",
      "Follow the skill format in this repo (SKILL.md with YAML frontmatter).",
      "Keep SKILL.md concise and move detailed content into references files.",
      "After creating the skill, summarize where files were created.",
    ].filter(Boolean);

    return parts.join("\n");
  };

  const handleLoad = () => {
    onLoadPrompt(buildSkillPrompt());
  };

  const handleAppend = () => {
    onAppendPrompt(buildSkillPrompt());
  };

  return (
    <section className="panel space-y-6">
      <div className="space-y-2">
        <h2 className="text-xl font-semibold">Skill Builder</h2>
        <p className="text-sm text-muted-foreground">
          Shape a new skill with structured inputs, then load it directly into
          the prompt. Perfect for repeatable workflows.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <label className="field">
          <span className="field-label">Skill name</span>
          <input
            className="input-surface"
            placeholder="ex: repo-audit"
            value={skillName}
            onChange={(event) => setSkillName(event.target.value)}
          />
        </label>
        <label className="field">
          <span className="field-label">Short description</span>
          <input
            className="input-surface"
            placeholder="One sentence summary"
            value={skillDescription}
            onChange={(event) => setSkillDescription(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">Primary goal</span>
          <input
            className="input-surface"
            placeholder="What should the skill make PhpBot better at?"
            value={skillGoal}
            onChange={(event) => setSkillGoal(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">Key use cases</span>
          <input
            className="input-surface"
            placeholder="Comma-separated or short list of workflows"
            value={skillUseCases}
            onChange={(event) => setSkillUseCases(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">References</span>
          <input
            className="input-surface"
            placeholder="Docs or schemas to add under references/"
            value={skillReferences}
            onChange={(event) => setSkillReferences(event.target.value)}
          />
        </label>
        <label className="field">
          <span className="field-label">Scripts</span>
          <input
            className="input-surface"
            placeholder="scripts/*.py or scripts/*.sh"
            value={skillScripts}
            onChange={(event) => setSkillScripts(event.target.value)}
          />
        </label>
        <label className="field">
          <span className="field-label">Assets</span>
          <input
            className="input-surface"
            placeholder="templates, fonts, boilerplate"
            value={skillAssets}
            onChange={(event) => setSkillAssets(event.target.value)}
          />
        </label>
        <label className="field md:col-span-2">
          <span className="field-label">Constraints</span>
          <input
            className="input-surface"
            placeholder="Any guardrails, policies, or required steps"
            value={skillNotes}
            onChange={(event) => setSkillNotes(event.target.value)}
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
