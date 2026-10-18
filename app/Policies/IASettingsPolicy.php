<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Chi può vedere e modificare le impostazioni IA (chiave API compresa): lo stesso permesso delle
 * impostazioni generali. Scoperta per nome da Laravel (App\Settings\IASettings → questa classe),
 * come PrintSettingsPolicy e BackupSettingsPolicy.
 */
class IASettingsPolicy
{
    public function manage(User $user): Response
    {
        return $user->checkPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value)
            ? Response::allow()
            : Response::deny(__('policies.manage_general_settings'));
    }
}
