<?php

require_once __DIR__ . '/../libs/assignment_management.php';

$checks = 0;
$failures = [];

function assignment_test(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

assignment_test(assignment_id('42') === 42, 'Valid numeric IDs must be accepted.');
assignment_test(assignment_id(7) === 7, 'Integer IDs must be accepted.');
assignment_test(assignment_id('0') === null, 'Zero must be rejected as an ID.');
assignment_test(assignment_id('-1') === null, 'Negative IDs must be rejected.');
assignment_test(assignment_id('1.5') === null, 'Fractional IDs must be rejected.');
assignment_test(assignment_id('1e2') === null, 'Scientific notation must be rejected.');
assignment_test(assignment_id([]) === null, 'Array IDs must be rejected.');

$validValues = [
    'grade_level' => 'Grade 11',
    'strand' => 'Academic',
    'section' => 'STEM-A',
    'title' => 'Data Structures Review',
    'description' => 'Complete the exercises and submit your work.'
];
assignment_test(assignment_validate($validValues) === [], 'Valid assignment details must pass validation.');

$invalidValues = $validValues;
$invalidValues['title'] = '';
$invalidValues['strand'] = 'Arts';
$invalidValues['section'] = str_repeat('A', 51);
$invalidErrors = assignment_validate($invalidValues);
assignment_test(count($invalidErrors) === 3, 'Invalid assignment details must report each invalid field.');
assignment_test(assignment_validate(array_merge($validValues, ['title' => str_repeat('A', 256)])) !== [], 'Long titles must be rejected.');

$_SESSION = [];
assignment_flash('success', 'Saved.');
$flash = assignment_take_flash();
assignment_test(is_array($flash) && $flash['type'] === 'success' && $flash['message'] === 'Saved.', 'Flash messages must round-trip.');
assignment_test(assignment_take_flash() === null, 'Flash messages must be one-shot.');

$_SESSION = [];
assignment_store_draft(9, $validValues);
$draft = assignment_take_draft(9);
assignment_test(is_array($draft) && $draft['title'] === $validValues['title'], 'Drafts must be available to the edit form.');
assignment_test(assignment_take_draft(9) === null, 'Drafts must be consumed after use.');
assignment_store_draft(9, $validValues);
assignment_store_draft(10, array_merge($validValues, ['title' => 'Another assignment']));
$preservedDraft = assignment_take_draft(9);
$otherDraft = assignment_take_draft(10);
assignment_test(is_array($preservedDraft) && $preservedDraft['title'] === $validValues['title'], 'Reading another assignment must not erase an existing draft.');
assignment_test(is_array($otherDraft) && $otherDraft['title'] === 'Another assignment', 'Drafts for different assignments must be preserved independently.');

$rules = assignment_upload_rules();
assignment_test($rules['max_bytes'] === 9 * 1024 * 1024, 'Upload limit must leave room for multipart overhead.');
assignment_test(isset($rules['extensions']['pdf'], $rules['extensions']['docx'], $rules['extensions']['mp4']), 'Required assignment file types must be supported.');

if ($failures) {
    fwrite(STDERR, "Assignment management tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Assignment management tests passed ({$checks} checks).\n";
