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
    ): Session {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new RuntimeException('A session requires a non-empty --task id.');
        }

        $id = $this->generateId($root, $taskId, $slug);
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
        );

        $this->writeMetadata($session);

        return $session;
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
        );
        $this->writeMetadata($updated);

        return $updated;
    }

    public function setStatus(Session $session, SessionStatus $status): Session
    {
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
        );
        $this->writeMetadata($updated);

        return $updated;
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
