<?php

$privatePromptsPath = storage_path('app/private/zen-prompts.php');
$prompts = is_file($privatePromptsPath) ? require $privatePromptsPath : [];

if (! is_array($prompts)) {
    throw new UnexpectedValueException('Private Zen prompts must return an array.');
}

return [

    'prompts' => $prompts,

    /*
    | Number of days saved consultations that contain detected personal
    | information are kept before they are deleted automatically.
    | Consultations without personal information are kept until the user
    | deletes them. Set to 0 to disable automatic deletion.
    */
    'consultation_retention_days' => (int) env('ZEN_CONSULTATION_RETENTION_DAYS', 365),

];
