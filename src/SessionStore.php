<?php

declare(strict_types=1);

namespace voku\AgentSession;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use SplFileObject;

/**
 * Filesystem-backed store for working-memory sessions under a sessions root
 * (by default `session_plan/`). One directory per session, with a
 * `session.json` metadata file plus the scaffolded working-memory files.
 */
final class SessionStore
{
    private const string METADATA_FILE = 'session.json';
    private const string STORE_LOCK_FILE = '.store.lock';

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

        $lock = $this->lockRoot($root);
        try {
            $this->assertNoOpenSession($root, $taskId, $ephemeral);

            return $this->createAtId(
                $root,
                $this->generateId($root, $taskId, $slug),
                $taskId,
                $by,
                $baseCommit,
                $ephemeral,
            );
        } finally {
            $this->unlockRoot($lock);
        }
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

        $lock = $this->lockRoot($root);
        try {
            $path = $this->pathFor($root, $id);
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException(sprintf('Cannot rehydrate existing Session: %s', $id));
            }
            $this->assertNoOpenSession($root, $taskId, $ephemeral);

            return $this->createAtId($root, $id, $taskId, $by, $baseCommit, $ephemeral);
        } finally {
            $this->unlockRoot($lock);
        }
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

        $contents = file_get_contents($metadataPath);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read Session metadata: ' . $metadataPath);
        }
        $data = $this->decode($contents, $metadataPath);
        $this->assertSupportedMetadataVersion($data, $metadataPath);

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

    /** @return list<Session> */
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
     * The single open governed Session for one task, or null when none is open.
     *
     * This is the canonical resume lookup, and it counts the same Sessions the
     * allocation rule counts. Experiments are excluded from both: allowing a
     * state at `create()` that this method then reports as corruption would be
     * two definitions of "active" in one class, and the state permitted by the
     * more specific one always wins.
     *
     * `openForTask()` stays the honest raw view for reporting and for resolving
     * a session id a human typed - an experiment is genuinely open, it is just
     * not what a governed Run resumes.
     *
     * @throws AmbiguousActiveSession when more than one governed Session is open
     */
    public function activeForTask(string $root, string $taskId): ?Session
    {
        $open = array_values(array_filter(
            $this->openForTask($root, $taskId),
            static fn (Session $session): bool => !$session->ephemeral,
        ));
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
        $root = $this->rootFor($session);
        $lock = $this->lockRoot($root);
        try {
            $current = $this->load($root, $session->id);
            $updated = new Session(
                $current->id,
                $current->taskId,
                $current->status,
                $by !== null && trim($by) !== '' ? trim($by) : $current->claimedBy,
                $this->now(),
                $baseCommit !== null && trim($baseCommit) !== '' ? trim($baseCommit) : $current->baseCommit,
                $current->createdAt,
                $this->now(),
                $current->checkpoints,
                $current->path,
                $current->ephemeral,
                $current->closedAt,
                $current->closedReason,
            );
            $this->writeMetadata($updated);

            return $updated;
        } finally {
            $this->unlockRoot($lock);
        }
    }

    /**
     * Move a Session to another lifecycle status.
     *
     * The current metadata is reloaded under the store lock before the status
     * transition is evaluated. A stale in-memory Session therefore cannot
     * resurrect a status that another process already closed.
     */
    public function setStatus(Session $session, SessionStatus $status, ?string $reason = null): Session
    {
        $root = $this->rootFor($session);
        $lock = $this->lockRoot($root);
        try {
            $current = $this->load($root, $session->id);

            $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
            if ($reason !== null && !$status->isClosed()) {
                throw new RuntimeException('A close reason only applies to a closed Session status.');
            }

            if ($current->status === $status) {
                if ($reason === null || $current->closedReason === $reason) {
                    return $current;
                }

                throw new RuntimeException(sprintf(
                    'Session %s is already %s; its recorded reason cannot be rewritten.',
                    $current->id,
                    $status->value,
                ));
            }

            if ($current->status->isClosed()) {
                throw new RuntimeException(sprintf(
                    'Session %s is closed as %s and cannot be moved to %s.',
                    $current->id,
                    $current->status->value,
                    $status->value,
                ));
            }

            $updated = new Session(
                $current->id,
                $current->taskId,
                $status,
                $current->claimedBy,
                $current->claimedAt,
                $current->baseCommit,
                $current->createdAt,
                $this->now(),
                $current->checkpoints,
                $current->path,
                $current->ephemeral,
                $status->isClosed() ? $this->now() : null,
                $status->isClosed() ? $reason : null,
            );
            $this->writeMetadata($updated);

            return $updated;
        } finally {
            $this->unlockRoot($lock);
        }
    }

    /**
     * Reopen a Session that was closed as done, so a governed Run bound to it stays workable.
     *
     * A Run binds to exactly one Session id. `close()` is the normal end of that Session, but work
     * can legitimately continue afterwards - the closing Run's own review gate can demand a follow-up
     * change, for example. Without this transition the Run is sealed: its bound Session can no longer
     * become active, and a freshly started Session carries a different id that the Run does not accept.
     *
     * Deliberately narrow:
     * - only a Session closed as `done` reopens; `dropped` states a Session was abandoned, and that
     *   verdict is not silently reversible.
     * - the task must have no other open Session, so reopening cannot create a second one.
     * - a reason is required and recorded as a checkpoint, so the reopen stays auditable after the
     *   `closed_reason` field is cleared.
     */
    public function reopen(Session $session, string $reason): Session
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Reopening a Session requires a non-empty reason.');
        }

        $root = $this->rootFor($session);
        $lock = $this->lockRoot($root);
        try {
            $current = $this->load($root, $session->id);

            if ($current->status === SessionStatus::ACTIVE) {
                return $current;
            }

            if ($current->status !== SessionStatus::DONE) {
                throw new RuntimeException(sprintf(
                    'Session %s is %s and cannot be reopened; only a Session closed as done reopens.',
                    $current->id,
                    $current->status->value,
                ));
            }

            $this->assertNoOpenSession($root, $current->taskId, $current->ephemeral);

            $updated = new Session(
                $current->id,
                $current->taskId,
                SessionStatus::ACTIVE,
                $current->claimedBy,
                $current->claimedAt,
                $current->baseCommit,
                $current->createdAt,
                $this->now(),
                $current->checkpoints,
                $current->path,
                $current->ephemeral,
                null,
                null,
            );
            $this->writeMetadata($updated);
        } finally {
            $this->unlockRoot($lock);
        }

        // INFO: outside the store lock, because addCheckpoint() takes it again.
        return $this->addCheckpoint($updated, 'Session reopened', $reason);
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

        $root = $this->rootFor($session);
        $lock = $this->lockRoot($root);
        try {
            $current = $this->load($root, $session->id);
            $checkpointId = sprintf('%03d', count($current->checkpoints) + 1);
            $now = $this->now();

            $fileName = sprintf('checkpoints/%s-%s.md', $checkpointId, $this->slugify($title));
            $this->writeFile($current->path . '/' . $fileName, $this->scaffold->checkpoint($checkpointId, $title, $body));
            $this->appendFile(
                $current->path . '/checkpoints/index.md',
                sprintf("\n- %s %s (%s)\n", $checkpointId, $title, $now),
            );

            $checkpoints = $current->checkpoints;
            $checkpoints[] = ['id' => $checkpointId, 'title' => $title, 'created_at' => $now];

            $updated = new Session(
                $current->id,
                $current->taskId,
                $current->status,
                $current->claimedBy,
                $current->claimedAt,
                $current->baseCommit,
                $current->createdAt,
                $now,
                $checkpoints,
                $current->path,
                $current->ephemeral,
                $current->closedAt,
                $current->closedReason,
            );
            $this->writeMetadata($updated);

            return $updated;
        } finally {
            $this->unlockRoot($lock);
        }
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

        $root = $this->rootFor($session);
        $lock = $this->lockRoot($root);
        try {
            $current = $this->load($root, $session->id);
            $this->appendFile($current->path . '/' . $file, $this->scaffold->record($kind, $title, $body));
            $this->touchCurrent($current);
        } finally {
            $this->unlockRoot($lock);
        }
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
        $lock = $this->lockRoot($root);
        try {
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
        } finally {
            $this->unlockRoot($lock);
        }
    }

    public function pathFor(string $root, string $id): string
    {
        return rtrim($root, '/') . '/' . $id;
    }

    private function rootFor(Session $session): string
    {
        return dirname($session->path);
    }

    /**
     * Refuse a second open Session for one task - counting governed work only.
     *
     * An ephemeral Session is an experiment that is never approved and never
     * meant to be finished, so nobody closes it. Letting one block allocation
     * would rebuild the failure the flag exists to prevent: a throwaway
     * standing in the way of the real work, which is a gate punishing the
     * wrong thing. It neither blocks a governed Session nor is blocked by one.
     */
    private function assertNoOpenSession(string $root, string $taskId, bool $ephemeral): void
    {
        if ($ephemeral) {
            return;
        }

        $open = array_values(array_filter(
            $this->openForTask($root, $taskId),
            static fn (Session $session): bool => !$session->ephemeral,
        ));
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
        $path = $session->path . '/' . self::METADATA_FILE;
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        $contents = json_encode(
            $session->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException('Failed to write temporary Session metadata: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            $cleanupFailed = is_file($temporary) && !unlink($temporary);
            throw new RuntimeException(
                'Failed to replace Session metadata: ' . $path
                . ($cleanupFailed ? ' (temporary file left behind: ' . $temporary . ')' : ''),
            );
        }
    }

    private function touchCurrent(Session $session): void
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

    private function lockRoot(string $root): SplFileObject
    {
        $this->makeDirectory($root);
        $lock = new SplFileObject(rtrim($root, '/') . '/' . self::STORE_LOCK_FILE, 'c+');
        if (!$lock->flock(LOCK_EX)) {
            throw new RuntimeException('Unable to lock Session store: ' . $root);
        }

        return $lock;
    }

    private function unlockRoot(SplFileObject $lock): void
    {
        // This runs in a `finally`, so throwing here would replace an in-flight
        // exception with a message about the lock and destroy the real
        // diagnosis. Releasing the handle drops the lock regardless, so a
        // failed explicit unlock carries nothing worth losing that error over.
        $lock->flock(LOCK_UN);
    }

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $data */
    private function assertSupportedMetadataVersion(array $data, string $path): void
    {
        $version = $data['schema_version'] ?? null;
        if (!is_string($version) || !in_array($version, ['1.0', '1.1'], true)) {
            throw new UnsupportedSessionMetadataVersion($path, $version);
        }
    }

    /** @param array<string, mixed> $data */
    private function stringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string, mixed> $data */
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
