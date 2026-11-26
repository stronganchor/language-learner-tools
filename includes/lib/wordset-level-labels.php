<?php
if (!defined('ABSPATH')) {
    exit;
}

function ll_tools_wordset_level_labels(): array {
    return [
        /* translators: %d: curriculum level number */
        'level' => __('Level %d', 'll-tools-text-domain'),
        'allLessons' => __('All lessons', 'll-tools-text-domain'),
        'allLevels' => __('Back to levels', 'll-tools-text-domain'),
        /* translators: %d: curriculum level number */
        'continueLevel' => __('Continue Level %d', 'll-tools-text-domain'),
        'browse' => __('Browse lessons', 'll-tools-text-domain'),
        'seeAll' => __('See all lessons', 'll-tools-text-domain'),
        'back' => __('Back', 'll-tools-text-domain'),
        'more' => __('Show more lessons', 'll-tools-text-domain'),
        'learn' => __('Learn', 'll-tools-text-domain'),
        'practice' => __('Practice', 'll-tools-text-domain'),
        'reviewAll' => __('Review now', 'll-tools-text-domain'),
        'roundSize' => __('Words this round', 'll-tools-text-domain'),
        'leastRecent' => __('Least recently studied', 'll-tools-text-domain'),
        'complete' => __('Level mastered', 'll-tools-text-domain'),
        /* translators: %d: number of words due for review */
        'due' => __('Due for review (%d)', 'll-tools-text-domain'),
        'stats' => __('Level details', 'll-tools-text-domain'),
        /* translators: 1: number of learned words, 2: total words in the level. */
        'progressCount' => __('%1$d / %2$d learned', 'll-tools-text-domain'),
        'dueLabel' => __('Due for review', 'll-tools-text-domain'),
        'mastered' => __('Learned', 'll-tools-text-domain'),
        'working' => __('In progress', 'll-tools-text-domain'),
        'unseen' => __('New', 'll-tools-text-domain'),
        'hard' => __('Hard', 'll-tools-text-domain'),
        'starred' => __('Starred', 'll-tools-text-domain'),
        'words' => __('Words', 'll-tools-text-domain'),
        'lessons' => __('Lessons', 'll-tools-text-domain'),
        'lastStudy' => __('Last studied', 'll-tools-text-domain'),
        'never' => __('No activity yet', 'll-tools-text-domain'),
        'interval' => __('How often do you want to review words in this level?', 'll-tools-text-domain'),
        'days' => __('days', 'll-tools-text-domain'),
        'intervalHelp' => __('The interval starts again each time you study a word.', 'll-tools-text-domain'),
        'saving' => __('Saving…', 'll-tools-text-domain'),
        'saved' => __('Saved', 'll-tools-text-domain'),
        'loading' => __('Loading progress…', 'll-tools-text-domain'),
        'error' => __('Could not load progress.', 'll-tools-text-domain'),
        'scopeExpired' => __('Reload this page to update your levels.', 'll-tools-text-domain'),
        'reload' => __('Reload', 'll-tools-text-domain'),
        'launchError' => __('Could not start this activity.', 'll-tools-text-domain'),
        'saveError' => __('Could not save this interval.', 'll-tools-text-domain'),
        'retry' => __('Retry', 'll-tools-text-domain'),
    ];
}

/** Describe the targets of a practice round spanning several lessons. */
function ll_tools_wordset_level_practice_label(array $counts): string {
    $new = (int) ($counts['unseen'] ?? 0);
    $working = (int) ($counts['working'] ?? 0) + (int) ($counts['hard'] ?? 0);
    $learned = (int) ($counts['mastered'] ?? 0);
    $parts = [];
    if ($new) {
        /* translators: %d: number of new words in this practice round. */
        $parts[] = sprintf(_n('%d new word', '%d new words', $new, 'll-tools-text-domain'), $new);
    }
    if ($working) {
        /* translators: %d: number of words still in progress in this practice round. */
        $parts[] = sprintf(_n('%d word in progress', '%d words in progress', $working, 'll-tools-text-domain'), $working);
    }
    if ($learned) {
        /* translators: %d: number of already learned words in this practice round. */
        $parts[] = sprintf(_n('%d learned word', '%d learned words', $learned, 'll-tools-text-domain'), $learned);
    }
    return implode(' · ', $parts);
}
