#!/usr/bin/env python3
"""
MLX classify server for PhpBot.

Loads a tiny language model using Apple's MLX framework (optimized for
Apple Silicon via Metal/GPU) and serves classification requests over HTTP.

The model is loaded once on startup and kept in memory for fast inference.

Requirements:
    pip install mlx-lm

Usage:
    # Start the server (default: port 5127, model: SmolLM2-135M)
    python bin/mlx-classify-server.py

    # Custom port and model
    python bin/mlx-classify-server.py --port 5128 --model mlx-community/Qwen2.5-0.5B-Instruct-4bit

    # Test with curl
    curl -s http://localhost:5127/classify \
        -H 'Content-Type: application/json' \
        -d '{"prompt": "Classify this user request..."}'

Available tiny models (sorted by size):
    mlx-community/SmolLM2-135M-Instruct-4bit   ~80MB   (fastest, good enough for classification)
    mlx-community/SmolLM2-360M-Instruct-4bit   ~200MB  (slightly better quality)
    mlx-community/Qwen2.5-0.5B-Instruct-4bit   ~350MB  (better quality)
    mlx-community/Phi-3.5-mini-instruct-4bit    ~2GB    (high quality, still fast on M-series)
"""

import argparse
import json
import sys
import time
from http.server import HTTPServer, BaseHTTPRequestHandler

# ---------------------------------------------------------------------------
# Model loading
# ---------------------------------------------------------------------------

_model = None
_tokenizer = None
_model_name = None

def load_model(model_name: str):
    """Load model once and cache in memory."""
    global _model, _tokenizer, _model_name

    if _model is not None and _model_name == model_name:
        return

    try:
        from mlx_lm import load
    except ImportError:
        print("ERROR: mlx-lm not installed. Run: pip install mlx-lm", file=sys.stderr)
        sys.exit(1)

    print(f"Loading model: {model_name} ...", flush=True)
    start = time.time()
    _model, _tokenizer = load(model_name)
    _model_name = model_name
    elapsed = time.time() - start
    print(f"Model loaded in {elapsed:.1f}s", flush=True)


def generate_response(prompt: str, max_tokens: int = 256, temperature: float = 0.1) -> str:
    """Generate a response from the loaded model."""
    from mlx_lm import generate

    # Format as a simple chat prompt
    messages = [
        {"role": "system", "content": "You are a task classifier. Respond with only valid JSON."},
        {"role": "user", "content": prompt},
    ]

    # Apply chat template if available
    if hasattr(_tokenizer, "apply_chat_template"):
        formatted = _tokenizer.apply_chat_template(
            messages, tokenize=False, add_generation_prompt=True
        )
    else:
        formatted = f"System: You are a task classifier. Respond with only valid JSON.\n\nUser: {prompt}\n\nAssistant:"

    response = generate(
        _model,
        _tokenizer,
        prompt=formatted,
        max_tokens=max_tokens,
        temp=temperature,
    )

    return response.strip()


# ---------------------------------------------------------------------------
# HTTP server
# ---------------------------------------------------------------------------

class ClassifyHandler(BaseHTTPRequestHandler):
    """Handle /classify and /health endpoints."""

    def do_POST(self):
        if self.path == "/classify":
            self.handle_classify()
        else:
            self.send_error(404, "Not Found")

    def do_GET(self):
        if self.path == "/health":
            self.handle_health()
        elif self.path == "/":
            self.handle_health()
        else:
            self.send_error(404, "Not Found")

    def handle_classify(self):
        try:
            content_length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(content_length)
            data = json.loads(body)

            prompt = data.get("prompt", "")
            max_tokens = data.get("max_tokens", 256)

            if not prompt:
                self.send_json(400, {"error": "Missing 'prompt' field"})
                return

            start = time.time()
            response = generate_response(prompt, max_tokens)
            elapsed = time.time() - start

            self.send_json(200, {
                "content": response,
                "provider": "mlx",
                "model": _model_name,
                "inference_ms": round(elapsed * 1000),
            })

        except Exception as e:
            self.send_json(500, {"error": str(e), "provider": "mlx"})

    def handle_health(self):
        self.send_json(200, {
            "status": "ok",
            "provider": "mlx",
            "model": _model_name,
            "ready": _model is not None,
        })

    def send_json(self, status: int, data: dict):
        body = json.dumps(data).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        """Suppress default access logs; only log errors."""
        if args and "200" not in str(args[0]):
            super().log_message(format, *args)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description="MLX classify server for PhpBot",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s                                          # Default model + port
  %(prog)s --model mlx-community/Qwen2.5-0.5B-Instruct-4bit
  %(prog)s --port 5128
  %(prog)s --once "Classify: create a new PHP file"  # One-shot mode (no server)
""",
    )
    parser.add_argument(
        "--model",
        default="mlx-community/SmolLM2-135M-Instruct-4bit",
        help="MLX model to load (default: SmolLM2-135M-Instruct-4bit)",
    )
    parser.add_argument(
        "--port",
        type=int,
        default=5127,
        help="HTTP port to listen on (default: 5127)",
    )
    parser.add_argument(
        "--once",
        type=str,
        default=None,
        help="One-shot mode: classify a single prompt and exit (no server)",
    )
    args = parser.parse_args()

    # Load the model
    load_model(args.model)

    # One-shot mode
    if args.once:
        response = generate_response(args.once)
        print(response)
        return

    # Server mode
    server = HTTPServer(("127.0.0.1", args.port), ClassifyHandler)
    print(f"MLX classify server running at http://127.0.0.1:{args.port}")
    print(f"Model: {args.model}")
    print(f"Endpoints: POST /classify, GET /health")
    print(f"Press Ctrl+C to stop", flush=True)

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down...")
        server.server_close()


if __name__ == "__main__":
    main()
