<?php

declare(strict_types=1);

namespace BashBox\Commands\Input;

use BashBox\Commands\CommandContext;

final class MultiFileInputReader implements InputReaderInterface
{
    public function read(array $files, CommandContext $commandContext): InputContent
    {
        if ($files === []) {
            $files = ['-'];
        }

        $allContent = '';
        $fileData = [];

        foreach ($files as $file) {
            if ($file === '-') {
                $content = $commandContext->stdin;
                $allContent .= $content;
                $fileData[] = ['name' => '-', 'content' => $content];
            } else {
                $path = $this->resolvePath($commandContext, $file);
                $content = $commandContext->fs->readFile($path);
                $allContent .= $content;
                $fileData[] = ['name' => $file, 'content' => $content];
            }
        }

        $inputSource = $this->determineSource($files);

        return new InputContent(
            content: $allContent,
            files: $fileData,
            source: $inputSource,
        );
    }

    private function resolvePath(CommandContext $commandContext, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $commandContext->fs->resolvePath($commandContext->cwd, $path);
    }

    /**
     * @param  list<string>  $files
     */
    private function determineSource(array $files): InputSource
    {
        if (count($files) === 1 && $files[0] === '-') {
            return InputSource::STDIN;
        }

        if (count($files) === 1) {
            return InputSource::SINGLE_FILE;
        }

        return InputSource::MULTIPLE_FILES;
    }
}
