<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * Projects a resumable handoff packet out of a Session's working memory.
 *
 * It only reads formats this package itself writes - the scaffold's headings,
 * `record()`'s entries, `addCheckpoint()`'s files - so the parsing is a read of
 * our own output rather than a guess about a free-form document. Anything the
 * agent wrote outside those shapes is left alone instead of being
 * misinterpreted, and a section still holding its scaffold placeholder is
 * reported as empty rather than handed on as content.
 */
final readonly class SessionHandoffProjector
{
    private ValidationEvidenceStore $validationEvidence;

    public function __construct(?ValidationEvidenceStore $validationEvidence = null)
    {
        $this->validationEvidence = $validationEvidence ?? new ValidationEvidenceStore();
    }

    public function project(Session $session): SessionHandoff
    {
        $plan = $this->read($session, 'plan.md');

        return new SessionHandoff(
            $session->id,
            $session->taskId,
            $session->status,
            $session->claimedBy,
            $session->baseCommit,
            $this->section($plan, 'Goal'),
            $this->section($plan, 'Next action'),
            $session->checkpoints,
            $this->latestCheckpoint($session),
            $this->recordTitles($this->read($session, 'decisions.md'), 'Decision'),
            $this->recordTitles($this->read($session, 'assumptions.md'), 'Assumption'),
            $this->validationEvidence->all($session),
            $session->closedReason,
        );
    }

    private function read(Session $session, string $relative): string
    {
        $path = $session->path . '/' . $relative;
        if (!is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : '';
    }

    /**
     * The body of one `## <heading>` section, or null when it is absent or is
     * still the scaffold's italic placeholder.
     */
    private function section(string $markdown, string $heading): ?string
    {
        $pattern = '/^##[ \t]+' . preg_quote($heading, '/') . '[ \t]*$(?<body>.*?)(?=^#{1,2}[ \t]|\z)/ms';
        if (preg_match($pattern, $markdown, $matches) !== 1) {
            return null;
        }

        return $this->meaningful($matches['body']);
    }

    private function latestCheckpoint(Session $session): ?string
    {
        $checkpoints = $session->checkpoints;
        if ($checkpoints === []) {
            return null;
        }

        $latest = $checkpoints[count($checkpoints) - 1];
        foreach (glob($session->path . '/checkpoints/' . $latest['id'] . '-*.md') ?: [] as $path) {
            $contents = file_get_contents($path);
            if (is_string($contents)) {
                return $this->meaningful(preg_replace('/^#[ \t].*$/m', '', $contents) ?? '');
            }
        }

        return null;
    }

    /**
     * Titles of `## <Kind>: <title>` entries, newest last.
     *
     * Titles only: the packet points at what was decided, and the reasoning
     * stays in the file for an agent that needs to go deeper. A handoff that
     * inlines everything is the transcript it was meant to replace.
     *
     * @return list<string>
     */
    private function recordTitles(string $markdown, string $kind): array
    {
        if (preg_match_all('/^##[ \t]+' . preg_quote($kind, '/') . ':[ \t]*(?<title>.+?)[ \t]*$/m', $markdown, $matches) === false) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), $matches['title']),
            static fn (string $title): bool => $title !== '',
        ));
    }

    /**
     * Content unless it is only scaffold guidance.
     *
     * The scaffold writes its instructions as italic lines. A section that
     * still holds nothing else was never filled in, and reporting that
     * placeholder as the next action would hand a resuming agent an
     * instruction to invent one.
     */
    private function meaningful(string $body): ?string
    {
        $kept = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\*[^*].*\*$/', trim($line)) === 1) {
                continue;
            }
            $kept[] = $line;
        }

        return $kept === [] ? null : trim(implode("\n", $kept));
    }
}
