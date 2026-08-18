<?php

declare(strict_types=1);

namespace voku\AgentSession;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

/**
 * Filesystem-backed store for working-memory sessions under a sessions root
 * (by default `session_plan/`). One directory per session, with a
 * `session.json` metadata file plus the scaffolded working-memory files.
 */
final class SessionStore
{
    private const string METADATA_FILE = 'session.json';

    private readonly SessionScaffold $scaffold;

    public function __construct(?SessionScaffold $scaffold = null)
    {
        $this->scaffold = $scaffold ?? new SessionScaffold();
    }

    public function create(
        string $root,
        string $taskId,
        ?string $slug = null,
        ?string $by = null,
        ?string $baseCommit = null,
        bool $ephemeral = false,
    ): Session {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new RuntimeException('A session requires a non-empty --task id.');
        }
        $this->assertNoOpenSession($root, $taskId);

        return $this->createAtId(
            $root,
            $this->generateId($root, $taskId, $slug),
            $taskId,
            $by,
            $baseCommit,
            $ephemeral,
        );
    }

    /**
     * Recreate pruneable working memory at an identity that is already owned by
     * durable caller state, for example a governed Run.
     *
     * This deliberately does not derive or replace the id. Callers must supply
     * an exact path-safe Session id and the target must no longer exist.
     */
    public function rehydrate(
        string $root,
        string $id,
        string $taskId,
        ?string $by = null,
        ?string $baseCommit = null,
        bool $ephemeral = false,
    ): Session {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new RuntimeException('A session requires a non-empty --task id.');
        }

        $id = trim($id);
        if ($id === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1) {
            throw new RuntimeException('A rehydrated session requires a path-safe exact id.');
        }

        $path = $this->pathFor($root, $id);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException(sprintf('Cannot rehydrate existing Session: %s', $id));
        }
        $this->assertNoOpenSession($root, $taskId);

        return $this->createAtId($root, $id, $taskId, $by, $baseCommit, $ephemeral);
    }

    public function exists(string $root, string $id): bool
    {
        return is_file($this->pathFor($root, $id) . '/' . self::METADATA_FILE);
    }

    public function load(string $root, string $id): Session
    {
        $path = $this->pathFor($root, $id);
        $metadataPath = $path . '/' . self::METADATA_FILE;
        if (!is_file($metadataPath)) {
            throw new RuntimeException(sprintf('Session not found: %s', $id));
        }

        $data = $this->decode((string) file_get_contents($metadataPath), $metadataPath);

        $status = SessionStatus::tryFromString($this->stringField($data, 'status') ?? 'active') ?? SessionStatus::ACTIVE;

        return new Session(
            $this->stringField($data, 'id') ?? $id,
            $this->stringField($data, 'task_id') ?? '',
            $status,
            $this->nullableStringField($data, 'claimed_by'),
            $this->nullableStringField($data, 'claimed_at'),
            $this->nullableStringField($data, 'base_commit'),
            $this->stringField($data, 'created_at') ?? $this->now(),
            $this->stringField($data, 'updated_at') ?? $this->now(),
            $this->checkpointsField($data),
            $path,
            ($data['ephemeral'] ?? false) === true,
            $this->nullableStringField($data, 'closed_at'),
            $this->nullableStringField($data, 'closed_reason'),
        );
    }

    /**
     * @return list<Session>
     */
    public function all(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $sessions = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($this->exists($root, $entry)) {
                $sessions[] = $this->load($root, $entry);
            }
        }

        usort($sessions, static fn (Session $a, Session $b): int => strcmp($a->id, $b->id));

        return $sessions;
    }

    /**
     * Every Session that served one task, oldest id first.
     *
     * @return list<Session>
     */
    public function allForTask(string $root, string $taskId): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new RuntimeException('Selecting Sessions requires a non-empty task id.');
        }

        return array_values(array_filter(
            $this->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId,
        ));
    }

    /**
     * The Sessions for one task that are still open working memory.
     *
     * Returned as a list rather than a single Session on purpose: a reporting
     * caller has to be able to say "two are open" instead of failing, which is
     * the only honest thing to render for a pre-existing broken state.
     *
     * @return list<Session>
     */
    public function openForTask(string $root, string $taskId): array
    {
        return array_values(array_filter(
            $this->allForTask($root, $taskId),
            static fn (Session $session): bool => !$session->status->isClosed(),
        ));
    }

    /**
     * The single open Session for one task, or null when none is open.
     *
     * New and rehydrated Sessions are rejected while another Session for the
     * task remains open. More than one open Session can therefore only be a
     * pre-existing or externally-corrupted state, which callers must report
     * rather than resolve by picking a winner.
     *
     * @throws AmbiguousActiveSession when more than one Session is open
     */
    public function activeForTask(string $root, string $taskId): ?Session
    {
        $open = $this->openForTask($root, $taskId);
        if (count($open) > 1) {
            throw new AmbiguousActiveSession(
                trim($taskId),
                array_map(static fn (Session $session): string => $session->id, $open),
            );
        }

        return $open[0] ?? null;
    }

    public function claim(Session $session, ?string $by, ?string $baseCommit): Session
    {
        $updated = new Session(
            $session->id,
            $session->taskId,
            $session->status,
            $by !== null && trim($by) !== '' ? trim($by) : $session->claimedBy,
            $this->now(),
            $baseCommit !== null && trim($baseCommit) !== '' ? trim($baseCommit) : $session->baseCommit,
            $session->createdAt,
            $this->now(),
            $session->checkpoints,
            $session->path,
            $session->ephemeral,
            $session->closedAt,
            $session->closedReason,
        );
        $this->writeMetadata($updated);

        return $updated;
    }

    /**
     * Move a Session to another lifecycle status.
     *
     * A closed Session status is terminal. `create()` allocates fresh working
     * memory and `rehydrate()` restores caller-authorized historical identity,
     * so re-opening a finished Session would only make a governed Run look live
     * again without any owner having decided that it is.
     */
    public function setStatus(Session $session, SessionStatus $status, ?string $reason = null): Session
    {
        if ($session->status === $status) {
            if ($reason === null || trim($reason) === '' || $session->closedReason === trim($reason)) {
                return $session;
            }

            throw new RuntimeException(sprintf(
                'Session %s is already %s; its recorded reason cannot be rewritten.',
                $session->id,
                $status->value,
            ));
        }

        if ($session->status->isClosed()) {
            throw new RuntimeException(sprintf(
                'Session %s is closed as %s and cannot be moved to %s.',
                $session->id,
                $session->status->value,
                $status->value,
            ));
        }

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($reason !== null && !$status->isClosed()) {
            throw new RuntimeException('A close reason only applies to a closed Session status.');
        }

        $updated = new Session(
            $session->id,
            $session->taskId,
            $status,
            $session->claimedBy,
            $session->claimedAt,
            $session->baseCommit,
            $session->createdAt,
            $this->now(),
            $session->checkpoints,
            $session->path,
            $session->ephemeral,
            $status->isClosed() ? $this->now() : null,
            $status->isClosed() ? $reason : null,
        );
        $this->writeMetadata($updated);

        return $updated;
    }

    /**
     * Retire working memory with the reason it was retired.
     *
     * The reason explains the Session while that pruneable working memory still
     * exists. Durable lifecycle owners remain responsible for recording any
     * reason that must survive Session pruning.
     */
    public function close(Session $session, SessionStatus $status, ?string $reason = null): Session
    {
        if (!$status->isClosed()) {
            throw new RuntimeException(sprintf(
                'Closing a Session requires a closed status, got %s.',
                $status->value,
            ));
        }

        return $this->setStatus($session, $status, $reason);
    }

    public function addCheckpoint(Session $session, string $title, string $body): Session
    {
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('A checkpoint requires a --title.');
        }

        $checkpointId = sprintf('%03d', count($session->checkpoints) + 1);
        $now = $this->now();

        $fileName = sprintf('checkpoints/%s-%s.md', $checkpointId, $this->slugify($title));
        $this->writeFile($session->path . '/' . $fileName, $this->scaffold->checkpoint($checkpointId, $title, $body));
        $this->appendFile(
            $session->path . '/checkpoints/index.md',
            sprintf("\n- %s %s (%s)\n", $checkpointId, $title, $now),
        );

        $checkpoints = $session->checkpoints;
        $checkpoints[] = ['id' => $checkpointId, 'title' => $title, 'created_at' => $now];

        $updated = new Session(
            $session->id,
            $session->taskId,
            $session->status,
            $session->claimedBy,
            $session->claimedAt,
            $session->baseCommit,
            $session->createdAt,
            $now,
            $checkpoints,
            $session->path,
            $session->ephemeral,
            $session->closedAt,
            $session->closedReason,
        );
        $this->writeMetadata($updated);

        return $updated;
    }

    public function appendRecord(Session $session, string $kind, string $title, string $body): void
    {
        $file = match ($kind) {
            'decision' => 'decisions.md',
            'assumption' => 'assumptions.md',
            default => throw new RuntimeException(sprintf('Unknown record kind: %s (use decision or assumption).', $kind)),
        };

        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('A record requires a --title.');
        }

        $this->appendFile($session->path . '/' . $file, $this->scaffold->record($kind, $title, $body));
        $this->touch($session);
    }

    /**
     * Retention: delete closed sessions whose last update is older than
     * $keepDays. Returns the ids that were (or would be) removed.
     *
     * @param list<SessionStatus> $statuses statuses eligible for pruning
     * @return list<string>
     */
    public function prune(string $root, int $keepDays, array $statuses, bool $dryRun = false): array
    {
        $cutoff = time() - ($keepDays * 86400);
        $removed = [];

        foreach ($this->all($root) as $session) {
            if (!in_array($session->status, $statuses, true)) {
                continue;
            }
            $updatedTs = strtotime($session->updatedAt);
            if ($updatedTs === false || $updatedTs > $cutoff) {
                continue;
            }
            $removed[] = $session->id;
            if (!$dryRun) {
                $this->removeDirectory($session->path);
            }
        }

        return $removed;
    }

    public function pathFor(string $root, string $id): string
    {
        return rtrim($root, '/') . '/' . $id;
    }

    private function assertNoOpenSession(string $root, string $taskId): void
    {
        $open = $this->openForTask($root, $taskId);
        if ($open === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Task %s already has open Session%s %s; resume or close %s before allocating another.',
            $taskId,
            count($open) === 1 ? '' : 's',
            implode(', ', array_map(static fn (Session $session): string => $session->id, $open)),
            count($open) === 1 ? 'it' : 'them',
        ));
    }

    private function createAtId(
        string $root,
        string $id,
        string $taskId,
        ?string $by,
        ?string $baseCommit,
        bool $ephemeral,
    ): Session {
        $path = $this->pathFor($root, $id);
        $this->makeDirectory($path . '/checkpoints');

        foreach ($this->scaffold->files($taskId) as $relativePath => $contents) {
            $this->makeDirectory(dirname($path . '/' . $relativePath));
            $this->writeFile($path . '/' . $relativePath, $contents);
        }

        $now = $this->now();
        $session = new Session(
            $id,
            $taskId,
            SessionStatus::ACTIVE,
            $by !== null && trim($by) !== '' ? trim($by) : null,
            $by !== null && trim($by) !== '' ? $now : null,
            $baseCommit !== null && trim($baseCommit) !== '' ? trim($baseCommit) : null,
            $now,
            $now,
            [],
            $path,
            $ephemeral,
        );

        $this->writeMetadata($session);

        return $session;
    }

    private function generateId(string $root, string $taskId, ?string $slug): string
    {
        $base = $this->now('Y-m-d') . '-' . $this->slugify($slug ?? $taskId);
        $candidate = $base;
        $suffix = 2;
        while (is_dir($this->pathFor($root, $candidate))) {
            $candidate = $base . '-' . $suffix;
            ++$suffix;
        }

        return $candidate;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');

        return $value === '' ? 'session' : $value;
    }

    private function writeMetadata(Session $session): void
    {
        $this->makeDirectory($session->path);
        $this->writeFile(
            $session->path . '/' . self::METADATA_FILE,
            json_encode($session->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function touch(Session $session): void
    {
        $updated = new Session(
            $session->id,
            $session->taskId,
            $session->status,
            $session->claimedBy,
            $session->claimedAt,
            $session->baseCommit,
            $session->createdAt,
            $this->now(),
            $session->checkpoints,
            $session->path,
            $session->ephemeral,
            $session->closedAt,
            $session->closedReason,
        );
        $this->writeMetadata($updated);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json, string $path): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid session metadata in %s: %s', $path, $e->getMessage()));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid session metadata in %s.', $path));
        }

        $typed = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nullableStringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{id: string, title: string, created_at: string}>
     */
    private function checkpointsField(array $data): array
    {
        $raw = $data['checkpoints'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $checkpoints = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = $entry['id'] ?? null;
            $title = $entry['title'] ?? null;
            $createdAt = $entry['created_at'] ?? null;
            if (is_string($id) && is_string($title) && is_string($createdAt)) {
                $checkpoints[] = ['id' => $id, 'title' => $title, 'created_at' => $createdAt];
            }
        }

        return $checkpoints;
    }

    private function now(string $format = DateTimeInterface::ATOM): string
    {
        return (new DateTimeImmutable('now'))->format($format);
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created.', $path));
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Failed to write file: %s', $path));
        }
    }

    private function appendFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, FILE_APPEND) === false) {
            throw new RuntimeException(sprintf('Failed to append to file: %s', $path));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
