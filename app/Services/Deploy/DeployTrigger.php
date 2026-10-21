<?php

namespace App\Services\Deploy;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Spawns the pull-deploy script in the background (cPanel-safe nohup).
 */
class DeployTrigger
{
    public function scriptPath(): string
    {
        return base_path('scripts/pull-deploy.sh');
    }

    /** The account home on cPanel: the directory above the checkout. */
    public function homePath(): string
    {
        return dirname(base_path());
    }

    public function logPath(): string
    {
        return (string) config('deploy.log');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function triggerAsync(?string $expectedSha = null): array
    {
        $script = $this->scriptPath();

        if (! is_file($script)) {
            throw new RuntimeException('pull-deploy.sh not found at '.$script);
        }

        if (! is_executable($script)) {
            @chmod($script, 0755);
        }

        $shaArg = ($expectedSha !== null && preg_match('/^[0-9a-f]{40}$/i', $expectedSha) === 1)
            ? escapeshellarg($expectedSha)
            : '';

        // Explicit HOME: PHP-FPM workers often omit it, which breaks `set -u`.
        $cmd = sprintf(
            'export HOME=%s; nohup bash %s %s >> %s 2>&1 &',
            escapeshellarg($this->homePath()),
            escapeshellarg($script),
            $shaArg,
            escapeshellarg($this->logPath()),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes, dirname($script));

        if (! is_resource($process)) {
            Log::error('Deploy trigger failed to spawn process', ['cmd' => $cmd]);

            return ['ok' => false, 'message' => 'Unable to spawn deploy process (proc_open blocked?)'];
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);

        Log::info('Deploy triggered', ['expected_sha' => $expectedSha, 'log' => $this->logPath()]);

        return ['ok' => true, 'message' => 'Deploy started'];
    }
}
