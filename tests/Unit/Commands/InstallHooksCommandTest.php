<?php

declare(strict_types=1);

use App\Commands\InstallHooksCommand;

describe('InstallHooksCommand', function () {
    it('returns failure when not in git repository', function () {
        // Test in a non-git directory
        $tempDir = sys_get_temp_dir().'/not-a-git-repo-'.uniqid();
        mkdir($tempDir);

        try {
            chdir($tempDir);

            $this->artisan('install')
                ->expectsOutput('Not in a git repository')
                ->assertFailed();
        } finally {
            chdir('/');
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    });

    it('has the correct signature', function () {
        $command = new InstallHooksCommand;
        expect($command)->toHaveProperty('signature');
    });

    it('has correct description', function () {
        $command = new InstallHooksCommand;
        expect($command)->toHaveProperty('description');
    });
});
