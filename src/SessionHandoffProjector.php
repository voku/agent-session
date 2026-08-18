<?php

declare(strict_types=1);

namespace voku\AgentSession;

use RuntimeException;

/**
 * Projects a resumable handoff packet out of a Session's working memory.
 *
 * It only reads formats this package itself writes - the scaffold's headings,
 * `record()`'s entries, `addCheckpoint()`'s files - so the parsing is a read of
 * our own output rather than a guess about a free-form document. Anything the
 * agent wrote outside those shapes is left alone instead of being
 * misinterpreted, and a section still holding its exact scaffold placeholder is
 * reported as empty rather than handed on as content.
 */
final readonly class SessionHandoffProjector
{
    /** @var list<string> */
    private const array SCAFFOLD_PLACEHOLDERS = [
        '*What durable intent does this session serve? (mirror the task, do not redefine it)*',
        '*none yet*',
        '*the single next concrete step*',
        '- *boundaries that must hold (scope, permissions, types, no unrelated migration)*',
        '- *the observable conditions that prove completion*',
        '*Record what you had to assume because the repository did not answer it.*',
        '*Each assumption stays an assumption until validated.*',
        '*Record observable decisions (context, decision, reason, validation).*',
        '*Not a transcript of internal reasoning.*',
        '*Commands run and their status. Pending checks are honest, not hidden.*',
        '*Resumable state. Each checkpoint records completed / open / blocked / next action.*',
    ];

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

        // The read failure is handled below and re-reported with the path and
        // what was being read, so PHP's own diagnostic adds nothing. Letting it
        // through makes every run of the unreadable-file regression print a
        // warning, and a suite that always says "there were issues" cannot
        // report a real one.
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read Session handoff content: ' . $path);
        }

        return $contents;
    }

    /**
     * The body of one `## <heading>` section, or null when it is absent or is
     * still the scaffold's exact placeholder.
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
            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read Session handoff checkpoint: ' . $path);
            }

            return $this->meaningful(preg_replace('/^#[ \t].*$/m', '', $contents) ?? '');
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
     * Content unless it is only exact scaffold guidance.
     *
     * Formatting is not authority: an agent may intentionally author italic
     * Markdown. Only literals emitted by SessionScaffold are removed.
     */
    private function meaningful(string $body): ?string
    {
        $kept = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = rtrim($line);
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (in_array($trimmed, self::SCAFFOLD_PLACEHOLDERS, true)) {
                continue;
            }
            $kept[] = $line;
        }

        return $kept === [] ? null : trim(implode("\n", $kept));
    }
}
