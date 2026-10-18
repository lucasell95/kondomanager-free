<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BackupServiceProvider;
use App\Providers\IAServiceProvider;
use App\Providers\MailConfigServiceProvider;
use App\Providers\UpgradePatchServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    BackupServiceProvider::class,
    IAServiceProvider::class,
    MailConfigServiceProvider::class,
    UpgradePatchServiceProvider::class,
];
