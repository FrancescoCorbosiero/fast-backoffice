<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Campaign delivery (design.md §4.2, Mail)
|--------------------------------------------------------------------------
|
| Which Laravel mailer carries campaigns ("ses" in production: the AWS keys
| are in config/services.php from the environment), and the SNS topic whose
| notifications the app accepts for SES events. The sender, the rate and
| the retry window are settings (Impostazioni › Campagne email).
|
*/

return [

    'mailer' => env('CAMPAIGN_MAILER', 'ses'),

    // Only notifications from this topic are processed; empty = any topic (development only).
    'sns_topic_arn' => env('SES_SNS_TOPIC_ARN'),

];
