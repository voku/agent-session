<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentSession\OperatingPromptSelection;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStatus;
use voku\AgentSession\WorkBriefStore;

final class OperatingPromptWorkBriefTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-operating-prompt-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSelectionAndManifestArePartOfApprovedRevisionAndHistory(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.prompts');
        $briefs = new WorkBriefStore();
        $selection = new OperatingPromptSelection('coverage-mutation', [
            'mutation_command' => 'vendor/bin/infection',
            'minimum_percentage_points' => 10,
        ]);

        $created = $briefs->create(
            $session,
            'Harden parser tests.',
            ['src/Parser.php', 'tests/ParserTest.php'],
            ['Keep public API unchanged.'],
            ['composer ci'],
            [],
            [],
            'vendor/voku/agent-skills/skills/operational-prompting/operating-prompts.json',
            [$selection],
        );
        $briefs->approve($session, 'lars');

        $approved = $briefs->load($session);
        self::assertSame(WorkBriefStatus::APPROVED, $approved->status);
        self::assertSame($created->operatingPromptManifest, $approved->operatingPromptManifest);
        self::assertSame([$selection->toArray()], array_map(
            static fn (OperatingPromptSelection $prompt): array => $prompt->toArray(),
            $approved->operatingPrompts,
        ));

        $revised = $briefs->revise(
            $session,
            'Harden parser tests.',
            ['src/Parser.php', 'tests/ParserTest.php'],
            ['Keep public API unchanged.'],
            ['composer ci'],
            [],
            [],
            'vendor/voku/agent-skills/skills/operational-prompting/operating-prompts.json',
            [new OperatingPromptSelection('reproduce-before-fix')],
        );

        self::assertSame(2, $revised->revision);
        self::assertNull($briefs->approval($session));
        self::assertSame('reproduce-before-fix', $revised->operatingPrompts[0]->id);

        $history = json_decode(
            (string) file_get_contents($session->path . '/work-brief-history/work-brief.001.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('coverage-mutation', $history['operating_prompts'][0]['id']);
        self::assertSame(10, $history['operating_prompts'][0]['arguments']['minimum_percentage_points']);
        self::assertFileExists($session->path . '/work-brief-history/approval.001.json');
    }

    public function testOperatingPromptsRequireExplicitManifestSource(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.prompts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an explicit operating-prompt manifest path');

        (new WorkBriefStore())->create(
            $session,
            'Harden parser tests.',
            ['src/Parser.php'],
            [],
            ['composer ci'],
            operatingPrompts: [new OperatingPromptSelection('reproduce-before-fix')],
        );
    }

    public function testOlderBriefWithoutOperatingPromptFieldsRemainsReadable(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.legacy-prompts');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep compatibility.', ['src/Legacy.php'], [], ['composer ci']);

        $path = $session->path . '/work-brief.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unset($data['operating_prompt_manifest'], $data['operating_prompts']);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $loaded = $briefs->load($session);
        self::assertNull($loaded->operatingPromptManifest);
        self::assertSame([], $loaded->operatingPrompts);
    }

    public function testSelectionRejectsNonDeterministicArgumentTypesAndSortsArguments(): void
    {
        $selection = new OperatingPromptSelection('coverage-mutation', [
            'z_value' => true,
            'a_value' => 10,
        ]);
        self::assertSame(['a_value' => 10, 'z_value' => true], $selection->arguments);

        $this->expectException(\InvalidArgumentException::class);
        new OperatingPromptSelection('coverage-mutation', ['ratio' => 0.5]);
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
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
