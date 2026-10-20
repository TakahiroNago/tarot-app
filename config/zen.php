<?php

return [

    /*
    | Number of days saved consultations that contain detected personal
    | information are kept before they are deleted automatically.
    | Consultations without personal information are kept until the user
    | deletes them. Set to 0 to disable automatic deletion.
    */
    'consultation_retention_days' => (int) env('ZEN_CONSULTATION_RETENTION_DAYS', 365),

];
