import type { BotRun } from "@/types/bot";

type HeaderProps = {
  isRunning: boolean;
  activeRun: BotRun | null;
};

export function Header({ isRunning, activeRun }: HeaderProps) {
  return (
    <header className="grid gap-6 lg:grid-cols-[1.3fr_0.7fr] lg:items-end">
      <div className="space-y-5">
        <p className="eyebrow">PhpBot Console</p>
        <h1 className="display-title">
          Direct the bot, watch the work unfold, and keep control in one place.
        </h1>
        <p className="text-base text-muted-foreground md:text-lg">
          Power-user controls for dialing in models, budgets, and iterations
          while the agent works through your repo.
        </p>
      </div>
      <div className="panel panel-accent space-y-3">
        <div className="flex items-center justify-between text-sm">
          <span className="uppercase tracking-[0.2em] text-muted-foreground">
            Status
          </span>
          <span
            className={`status-pill ${
              isRunning ? "status-live" : "status-idle"
            }`}
          >
            {isRunning ? "Running" : "Idle"}
          </span>
        </div>
        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
          <span className="chip">CLI-powered</span>
          <span className="chip">Claude Agents</span>
          <span className="chip">Local tools</span>
        </div>
        <p className="text-sm text-muted-foreground">
          {activeRun
            ? `Active run started ${activeRun.startedAt}`
            : "No run selected yet."}
        </p>
      </div>
    </header>
  );
}
