<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\SelfImprovement;

/**
 * Represents a proposed self-improvement to PHPBot.
 *
 * Captures everything the FeaturePipeline needs to branch, build, test,
 * and submit a pull request for a community-reviewed change.
 */
class ImprovementProposal
{
    public function __construct(
        /** Human-readable description of the feature from the user or detector. */
        public readonly string $description,

        /**
         * Change type: 'skill' | 'tool' | 'core'
         * Determines which files will be created/modified and which quorum is required.
         */
        public readonly string $type,

        /**
         * Risk tier: 'skill' | 'tool' | 'core' | 'blocked'
         * Computed by ProtectedPathGuard; 'blocked' means the pipeline must abort.
         */
        public readonly string $riskTier,

        /** kebab-case slug derived from the description (e.g. "slack-thread-summarizer") */
        public readonly string $slug,

        /** Full git branch name (e.g. "phpbot/feat-slack-thread-summarizer-20260220") */
        public readonly string $branchName,

        /**
         * How the proposal was initiated.
         * 'command'  = user typed /feature explicitly
         * 'passive'  = ImprovementDetector surfaced a gap, user confirmed
         */
        public readonly string $source = 'command',

        /** ISO-8601 timestamp when the proposal was created. */
        public readonly string $createdAt = '',
    ) {}

    public static function create(
        string $description,
        string $type,
        string $riskTier,
        string $slug,
        string $source = 'command',
    ): self {
        $date       = date('Ymd');
        $branchName = "phpbot/feat-{$slug}-{$date}";

        return new self(
            description: $description,
            type:        $type,
            riskTier:    $riskTier,
            slug:        $slug,
            branchName:  $branchName,
            source:      $source,
            createdAt:   date('c'),
        );
    }

    public function isBlocked(): bool
    {
        return $this->riskTier === 'blocked';
    }

    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'type'        => $this->type,
            'risk_tier'   => $this->riskTier,
            'slug'        => $this->slug,
            'branch_name' => $this->branchName,
            'source'      => $this->source,
            'created_at'  => $this->createdAt,
        ];
    }
}
