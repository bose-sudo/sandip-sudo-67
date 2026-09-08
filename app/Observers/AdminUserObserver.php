<?php

namespace App\Observers;

use App\Models\AdminUser;
use App\Models\FullAccessUserType;
use Illuminate\Support\Facades\Log;

/**
 * When a new AdminUser is created, check whether their user_type is configured
 * for full borrower access. If so, set is_all_borrower_accessible = true
 * automatically — no manual step required.
 *
 * Also fires on update in case someone changes a user's user_type.
 */
class AdminUserObserver
{
    public function created(AdminUser $user): void
    {
        $this->syncFullAccessFlag($user);
    }

    public function updated(AdminUser $user): void
    {
        // Only re-evaluate if the user_type column actually changed
        if ($user->wasChanged('user_type')) {
            $this->syncFullAccessFlag($user);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Grant the flag if the user's type is in full_access_user_types.
     * Never revoke it here — revocation is an explicit manual action.
     *
     * Design decision: we only auto-GRANT, never auto-REVOKE.
     * If an admin moves a user to a non-full-access type, the flag stays
     * until explicitly turned off. This prevents accidental access loss.
     */
    private function syncFullAccessFlag(AdminUser $user): void
    {
        if ($user->is_all_borrower_accessible) {
            // Already has full access — nothing to do
            return;
        }

        if (blank($user->user_type)) {
            return;
        }

        try {
            $shouldHaveFullAccess = FullAccessUserType::where('user_type_id', $user->user_type)->exists();

            if ($shouldHaveFullAccess) {
                // Use updateQuietly to avoid triggering another observer loop
                $user->updateQuietly(['is_all_borrower_accessible' => true]);
            }
        } catch (\Throwable $e) {
            Log::error('AdminUserObserver: failed to sync full access flag', [
                'user_id'   => $user->id,
                'user_type' => $user->user_type,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
