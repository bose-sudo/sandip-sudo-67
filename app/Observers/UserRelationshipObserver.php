<?php

namespace App\Observers;

use App\Models\UserHierarchyClosure;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserRelationshipObserver
{
    /**
     * When a new parent→child edge is created, update the closure table.
     * Also seed the self-reference for brand-new users.
     */
    public function created(UserRelationship $relationship): void
    {
        try {
            // Ensure self-references exist for both nodes before linking
            $this->ensureSelfReference($relationship->parent_user_id);
            $this->ensureSelfReference($relationship->child_user_id);

            DB::statement(
                'SELECT add_user_to_closure(?, ?)',
                [$relationship->parent_user_id, $relationship->child_user_id]
            );
        } catch (\Throwable $e) {
            Log::error('UserRelationshipObserver@created failed', [
                'parent' => $relationship->parent_user_id,
                'child'  => $relationship->child_user_id,
                'error'  => $e->getMessage(),
            ]);

            throw $e; // re-throw so the outer transaction rolls back
        }
    }

    /**
     * When a relationship is soft-deactivated (is_active = false),
     * we intentionally leave the closure table intact.
     * Full removal only happens on hard delete.
     */
    public function updated(UserRelationship $relationship): void
    {
        // Nothing — deactivating a relationship does not strip the closure.
        // Visibility is filtered by dealer_user_map.status instead.
    }

    /**
     * When a relationship is hard-deleted, prune the closure table.
     */
    public function deleting(UserRelationship $relationship): void
    {
        try {
            DB::statement(
                'SELECT remove_user_from_closure(?, ?)',
                [$relationship->parent_user_id, $relationship->child_user_id]
            );
        } catch (\Throwable $e) {
            Log::error('UserRelationshipObserver@deleting failed', [
                'parent' => $relationship->parent_user_id,
                'child'  => $relationship->child_user_id,
                'error'  => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------

    private function ensureSelfReference(int $userId): void
    {
        UserHierarchyClosure::insertOrIgnore([
            'ancestor_id'   => $userId,
            'descendant_id' => $userId,
            'depth'         => 0,
        ]);
    }
}
