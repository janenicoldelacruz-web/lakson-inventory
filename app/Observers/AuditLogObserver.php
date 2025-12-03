<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogObserver
{
    // Handle the created event
    public function created(Model $model)
    {
        // Check if the user is authenticated
        $user = auth()->user();  // This retrieves the currently authenticated user
        
        // If no user is authenticated, set $user to null or a default system value (e.g., 0)
        $userId = $user ? $user->id : 0;  // Use 0 or null for system actions

        AuditLog::create([
            'action' => 'created',
            'user_id' => $userId, // Use the user_id or system ID
            'entity_type' => get_class($model), // Dynamically set the entity type (e.g., 'Product', 'Sale')
            'entity_id' => $model->id, // Use the model’s ID
            'changes' => json_encode($model->getAttributes()), // New data for creation
        ]);
    }

    // Handle the updated event
    public function updated(Model $model)
    {
        $user = auth()->user(); // Retrieve the authenticated user
        $userId = $user ? $user->id : 0; // Default to 0 if no user is authenticated

        AuditLog::create([
            'action' => 'updated',
            'user_id' => $userId, // Use the user_id or system ID
            'entity_type' => get_class($model),
            'entity_id' => $model->id,
            'changes' => json_encode([
                'old' => $model->getOriginal(), // Old data for update
                'new' => $model->getChanges(),  // New data for update
            ]),
        ]);
    }

    // Handle the deleted event
    public function deleted(Model $model)
    {
        $user = auth()->user(); // Retrieve the authenticated user
        $userId = $user ? $user->id : 0; // Default to 0 if no user is authenticated

        AuditLog::create([
            'action' => 'deleted',
            'user_id' => $userId, // Use the user_id or system ID
            'entity_type' => get_class($model),
            'entity_id' => $model->id,
            'changes' => json_encode($model->getOriginal()), // Data before deletion
        ]);
    }
}
