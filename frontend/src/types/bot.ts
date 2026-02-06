export type BotProgress = {
  stage: string;
  message: string;
  ts: number;
};

export type BotResponse = {
  success: boolean;
  answer: string | null;
  error: string | null;
  iterations: number;
  tool_calls: unknown[];
  token_usage: Record<string, number>;
  analysis: Record<string, unknown>;
  progress?: BotProgress[];
  overrides_applied?: Record<string, unknown>;
  log_id?: string;
  log_tail?: string;
  run_id?: string;
};

export type BotRun = {
  id: string;
  prompt: string;
  startedAt: string;
  response?: BotResponse;
  failed?: string;
  payload?: Record<string, unknown>;
  logContent?: string;
  liveProgress?: BotProgress[];
};

export const defaultOverrides = {
  model: "claude-sonnet-4-5",
  fast_model: "claude-haiku-4-5",
  super_model: "claude-opus-4-5",
  max_iterations: 20,
  max_tokens: 4096,
  temperature: 0.7,
  timeout: 120,
};

export type Overrides = typeof defaultOverrides;

export const API_PATH = "/api/run.php";

export const examplePrompts = [
  "Scan the repo and summarize the main workflows.",
  "Create a tool that formats JSON files and apply it to config.",
  "List all PHP files and highlight any TODO comments.",
];
