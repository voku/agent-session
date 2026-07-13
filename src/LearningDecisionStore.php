<?php

declare(strict_types=1);

namespace voku\AgentSession;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class LearningDecisionStore
{
    private const string FILE = 'learning-decision.json';

    public function decide(Session $session, LearningDecision $decision, string $by, ?string $reason = null): LearningDecisionRecord
    {
        $by = trim($by);
        if ($by === '') {
            throw new RuntimeException('Learning decision requires --by <actor>.');
        }
        $reason = $reason === null ? null : trim($reason);
        $record = new LearningDecisionRecord($session->taskId, $decision, $by, $reason === '' ? null : $reason, (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM));
        if (file_put_contents($this->path($session), json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException('Unable to write learning decision.');
        }

        return $record;
    }

    public function find(Session $session): ?LearningDecisionRecord
    {
        $path = $this->path($session);
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid learning decision JSON: ' . $exception->getMessage());
        }
        $decision = is_array($data) && is_string($data['decision'] ?? null) ? LearningDecision::tryFrom($data['decision']) : null;
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['task_id'] ?? null) !== $session->taskId || $decision === null || !is_string($data['decided_by'] ?? null) || trim($data['decided_by']) === '' || !is_string($data['decided_at'] ?? null)) {
            throw new RuntimeException('Invalid learning decision record.');
        }

        return new LearningDecisionRecord($session->taskId, $decision, trim($data['decided_by']), is_string($data['reason'] ?? null) && trim($data['reason']) !== '' ? trim($data['reason']) : null, $data['decided_at']);
    }

    private function path(Session $session): string
    {
        return $session->path . '/' . self::FILE;
    }
}
