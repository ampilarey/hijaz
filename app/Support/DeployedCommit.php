<?php

namespace App\Support;

/**
 * Which commit is actually running here.
 *
 * The deploy is fire-and-forget: the webhook spawns the pull script with
 * nohup and answers 202 immediately, so a 202 means the deploy *started*. If
 * the pull then fails on the server, the old code keeps serving and answers
 * the homepage exactly as well as the new code would. Reading this lets the
 * workflow ask the only question that matters: is the commit I just deployed
 * the commit that is serving?
 *
 * It reads `.git` directly rather than shelling out to git, because cPanel
 * accounts commonly disable proc_open and exec for the web user.
 */
class DeployedCommit
{
    public static function sha(): ?string
    {
        $git = base_path('.git');

        // A worktree or submodule keeps `.git` as a file pointing elsewhere.
        if (is_file($git)) {
            $pointer = trim((string) @file_get_contents($git));

            if (! str_starts_with($pointer, 'gitdir: ')) {
                return null;
            }

            $git = trim(substr($pointer, 8));
        }

        if (! is_dir($git)) {
            return null;
        }

        $head = trim((string) @file_get_contents($git.'/HEAD'));

        if ($head === '') {
            return null;
        }

        // Detached HEAD holds the commit itself.
        if (! str_starts_with($head, 'ref: ')) {
            return self::valid($head);
        }

        $ref = trim(substr($head, 5));

        $loose = @file_get_contents($git.'/'.$ref);

        if (is_string($loose) && ($sha = self::valid(trim($loose))) !== null) {
            return $sha;
        }

        // A freshly cloned checkout keeps its refs packed rather than loose.
        return self::fromPackedRefs($git.'/packed-refs', $ref);
    }

    private static function fromPackedRefs(string $path, string $ref): ?string
    {
        $packed = @file_get_contents($path);

        if (! is_string($packed)) {
            return null;
        }

        foreach (explode("\n", $packed) as $line) {
            // "<sha> <ref>". Lines starting with # or ^ are headers and peeled
            // tags, neither of which is the commit a branch points at.
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line));

            if (is_array($parts) && count($parts) === 2 && $parts[1] === $ref) {
                return self::valid($parts[0]);
            }
        }

        return null;
    }

    private static function valid(string $candidate): ?string
    {
        return preg_match('/^[0-9a-f]{40}$/', $candidate) === 1 ? $candidate : null;
    }
}
