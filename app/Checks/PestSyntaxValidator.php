<?php

declare(strict_types=1);

namespace App\Checks;

use Symfony\Component\Finder\Finder;

final class PestSyntaxValidator implements CheckInterface
{
    public function name(): string
    {
        return 'Pest Syntax';
    }

    public function run(string $workingDirectory): CheckResult
    {
        $testsPath = $workingDirectory . '/tests';

        if (!is_dir($testsPath)) {
            return CheckResult::pass('No tests directory found');
        }

        $finder = new Finder();
        $finder->files()->in($testsPath)->name('*Test.php');

        $violations = [];

        foreach ($finder as $file) {
            $contents = $file->getContents();

            // Check for test() function usage (but not in comments)
            if (preg_match('/^\s*test\s*\(/m', $contents)) {
                $violations[] = $file->getRelativePathname() . ': Uses test() instead of describe/it blocks';
            }
        }

        if (empty($violations)) {
            return CheckResult::pass('All test files use describe/it syntax');
        }

        return CheckResult::fail(
            'Found test files using test() function instead of describe/it',
            $violations
        );
    }
}
