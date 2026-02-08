<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

/**
 * Central OS detection helper.
 *
 * Provides platform-aware command mappings so the agent's system prompts,
 * router cache, and tools use the correct commands for the current OS.
 */
class Platform
{
    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    public static function isMacOS(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Human-readable OS name for prompts.
     */
    public static function osName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macOS',
            'Linux' => 'Linux',
            'Windows' => 'Windows',
            default => PHP_OS_FAMILY,
        };
    }

    // -------------------------------------------------------------------------
    // Command Mappings
    // -------------------------------------------------------------------------

    /**
     * Text-to-speech command.
     */
    public static function ttsCommand(): string
    {
        return self::isMacOS() ? 'say' : 'espeak';
    }

    /**
     * Open file/URL/application command.
     */
    public static function openCommand(): string
    {
        return self::isMacOS() ? 'open' : 'xdg-open';
    }

    /**
     * Copy to clipboard command (pipe stdin to this).
     */
    public static function clipboardCopyCommand(): string
    {
        return self::isMacOS() ? 'pbcopy' : 'xclip -selection clipboard';
    }

    /**
     * Paste from clipboard command.
     */
    public static function clipboardPasteCommand(): string
    {
        return self::isMacOS() ? 'pbpaste' : 'xclip -selection clipboard -o';
    }

    /**
     * Audio playback command.
     */
    public static function audioPlayCommand(): string
    {
        return self::isMacOS() ? 'afplay' : 'paplay';
    }

    /**
     * Screenshot command.
     */
    public static function screenshotCommand(): string
    {
        return self::isMacOS() ? 'screencapture' : 'scrot';
    }

    /**
     * Desktop notification command.
     */
    public static function notifyCommand(): string
    {
        return self::isMacOS() ? 'osascript -e \'display notification' : 'notify-send';
    }

    /**
     * Service manager command.
     */
    public static function serviceManagerCommand(): string
    {
        return self::isMacOS() ? 'launchctl' : 'systemctl';
    }

    /**
     * Primary package manager.
     */
    public static function packageManagerCommand(): string
    {
        if (self::isMacOS()) {
            return 'brew install';
        }

        // Detect the available Linux package manager
        if (self::commandExists('apt-get')) {
            return 'sudo apt-get install -y';
        }
        if (self::commandExists('dnf')) {
            return 'sudo dnf install -y';
        }
        if (self::commandExists('yum')) {
            return 'sudo yum install -y';
        }
        if (self::commandExists('pacman')) {
            return 'sudo pacman -S --noconfirm';
        }

        return 'brew install'; // Linuxbrew fallback
    }

    /**
     * Memory usage command.
     */
    public static function memoryCommand(): string
    {
        return self::isMacOS() ? 'vm_stat | head -5' : 'free -h';
    }

    /**
     * CPU info command.
     */
    public static function cpuInfoCommand(): string
    {
        return self::isMacOS() ? 'sysctl -n machdep.cpu.brand_string' : 'lscpu | head -15';
    }

    /**
     * OS version command.
     */
    public static function osVersionCommand(): string
    {
        return self::isMacOS() ? 'sw_vers' : 'cat /etc/os-release';
    }

    // -------------------------------------------------------------------------
    // Prompt Helpers
    // -------------------------------------------------------------------------

    /**
     * Build OS-aware "Superpowers" section for the system prompt.
     */
    public static function superpowersPrompt(): string
    {
        $tts = self::ttsCommand();
        $open = self::openCommand();
        $clipCopy = self::isMacOS() ? 'pbcopy' : 'xclip';
        $clipPaste = self::isMacOS() ? 'pbpaste' : 'xclip -o';
        $audio = self::audioPlayCommand();
        $screenshot = self::screenshotCommand();
        $notify = self::isMacOS() ? 'osascript' : 'notify-send';
        $svcMgr = self::serviceManagerCommand();
        $pkgMgr = self::isMacOS() ? 'brew install' : 'apt/dnf/brew install';

        return <<<PROMPT
- **Make sound**: `{$tts} "hello"` (TTS), `{$audio} file.mp3` (play audio)
- **See the screen**: `{$screenshot}`, access the filesystem, read any file
- **Talk to the internet**: `curl` any API, `wget` any file, `ssh` to remote servers
- **Control the OS**: `{$open}` apps/URLs/files, `{$notify}` for notifications, `{$clipCopy}`/`{$clipPaste}` for clipboard, `{$svcMgr}` for services
- **Install anything**: `{$pkgMgr}`, `pip install`, `npm install`
- **Write & run code**: Create scripts in Python/Node/PHP/bash, execute them
- **Process data**: `jq` for JSON, `awk`/`sed` for text, `ffmpeg` for media
- **Discover capabilities**: Use the search_capabilities tool to find available skills and tools
PROMPT;
    }

    /**
     * Build OS-aware "Creative Problem Solving" bullet for the system prompt.
     */
    public static function osPlaygroundPrompt(): string
    {
        $os = self::osName();
        $tts = self::ttsCommand();
        $open = self::openCommand();
        $audio = self::audioPlayCommand();
        $screenshot = self::screenshotCommand();

        if (self::isMacOS()) {
            return "   - **The OS is your playground**: macOS has `say` (TTS), `osascript` (GUI/notifications), `pbcopy` (clipboard), `open` (launch anything), `afplay` (audio), `screencapture` (screenshots), and hundreds more built-in commands.";
        }

        return "   - **The OS is your playground**: Linux has `espeak`/`spd-say` (TTS), `notify-send` (notifications), `xclip` (clipboard), `xdg-open` (launch anything), `paplay`/`mpv` (audio), `scrot` (screenshots), and hundreds more built-in commands.";
    }

    /**
     * Build OS-aware credential workflow text.
     */
    public static function credentialSourcesText(): string
    {
        $sources = 'env vars, shell profiles, .env files';
        if (self::isMacOS()) {
            $sources .= ', and macOS Keychain';
        } elseif (self::isLinux()) {
            $sources .= ', and Linux secret storage (secret-tool/keyring)';
        }

        return $sources;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Check whether a CLI command exists on the system.
     */
    public static function commandExists(string $command): bool
    {
        $result = @shell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($command)));

        return $result !== null && trim($result) !== '';
    }

    /**
     * Check if a GUI file picker is available (zenity, kdialog, or osascript).
     */
    public static function hasFilePicker(): bool
    {
        if (self::isMacOS()) {
            return true; // osascript always available
        }

        return self::commandExists('zenity') || self::commandExists('kdialog');
    }

    /**
     * Get the file picker backend name for the current platform.
     */
    public static function filePickerBackend(): ?string
    {
        if (self::isMacOS()) {
            return 'osascript';
        }

        if (self::commandExists('zenity')) {
            return 'zenity';
        }

        if (self::commandExists('kdialog')) {
            return 'kdialog';
        }

        return null;
    }

    /**
     * Check if Linux secret storage is available (secret-tool from libsecret).
     */
    public static function hasLinuxSecretStorage(): bool
    {
        return self::isLinux() && self::commandExists('secret-tool');
    }
}
