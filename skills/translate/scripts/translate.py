#!/usr/bin/env python3
"""
Translate text between languages using deep-translator.
Supports 100+ languages with no API key required.

Usage:
    translate.py <text> --to <language> [--from <language>]
"""

import sys
import subprocess
import argparse


def ensure_dependencies():
    """Install deep-translator if not available."""
    try:
        import deep_translator  # noqa: F401
        return True
    except ImportError:
        print("Installing deep-translator...", file=sys.stderr)
        result = subprocess.run(
            [sys.executable, "-m", "pip", "install", "deep-translator", "-q"],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            print(f"Failed to install deep-translator: {result.stderr}", file=sys.stderr)
            return False
        print("deep-translator installed.", file=sys.stderr)
        return True


# Common language name to code mapping
LANGUAGE_ALIASES = {
    "chinese": "zh-CN", "mandarin": "zh-CN", "cantonese": "zh-TW",
    "japanese": "ja", "korean": "ko", "spanish": "es",
    "french": "fr", "german": "de", "italian": "it",
    "portuguese": "pt", "russian": "ru", "arabic": "ar",
    "hindi": "hi", "dutch": "nl", "swedish": "sv",
    "norwegian": "no", "danish": "da", "finnish": "fi",
    "polish": "pl", "turkish": "tr", "thai": "th",
    "vietnamese": "vi", "indonesian": "id", "malay": "ms",
    "hebrew": "iw", "greek": "el", "czech": "cs",
    "romanian": "ro", "hungarian": "hu", "ukrainian": "uk",
    "english": "en",
}


def resolve_language(lang):
    """Resolve language name or code to a valid code."""
    if lang is None:
        return "auto"
    lang_lower = lang.lower().strip()
    return LANGUAGE_ALIASES.get(lang_lower, lang_lower)


def translate_text(text, source, target):
    """Translate text and return the result."""
    from deep_translator import GoogleTranslator

    translator = GoogleTranslator(source=source, target=target)
    result = translator.translate(text)
    return result


def main():
    parser = argparse.ArgumentParser(description="Translate text between languages")
    parser.add_argument("text", help="Text to translate")
    parser.add_argument("--to", required=True, dest="target", help="Target language code or name")
    parser.add_argument("--from", dest="source", default=None, help="Source language (auto-detected if omitted)")

    args = parser.parse_args()

    if not ensure_dependencies():
        sys.exit(1)

    source = resolve_language(args.source)
    target = resolve_language(args.target)

    try:
        result = translate_text(args.text, source, target)
        print(f"Translation ({source} -> {target}):")
        print(result)
    except Exception as e:
        print(f"Error translating: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
