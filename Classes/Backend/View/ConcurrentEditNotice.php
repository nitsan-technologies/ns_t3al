<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Backend\View;

/**
 * Warning text for a file another backend session has open (see EditorViewDataProvider::checkAndAcquireEditorLock()).
 */
final class ConcurrentEditNotice
{
    /**
     * @param array{time?: int, username?: string} $foreignLock
     * @param callable(string): string $translateMod locallang_mod.xlf label lookup
     */
    public static function message(array $foreignLock, string $file, int $now, callable $translateMod): string
    {
        $minutes = intdiv(max(0, $now - (int)($foreignLock['time'] ?? $now)), 60);
        $editor = trim((string)($foreignLock['username'] ?? ''));
        if ($editor === '') {
            $editor = $translateMod('concurrent_edit.someone');
        }
        $labelKey = match ($minutes) {
            0 => 'concurrent_edit.message.lessThanMinute',
            1 => 'concurrent_edit.message.oneMinute',
            default => 'concurrent_edit.message.minutes',
        };
        return sprintf($translateMod($labelKey), $editor, $file, $minutes);
    }
}
