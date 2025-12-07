<?php

namespace App\Observers;

use App\Models\AuditLog;

class AuditLogObserver
{
    protected function log(string $event, $model, ?array $changes = null): void
    {
        $user    = auth()->user();
        $request = request();

        AuditLog::create([
            'user_id'     => $user?->id,
            'action'      => strtolower(class_basename($model)) . '_' . $event,
            'entity_type' => get_class($model),
            'entity_id'   => $model->getKey(),
            'changes'     => $changes,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
            'meta'        => [
                'url'    => $request?->fullUrl(),
                'method' => $request?->method(),
            ],
        ]);
    }

    public function created($model): void
    {
        // TEMP: prove this observer is firing
       // dd('AuditLogObserver CREATED fired', get_class($model), $model->id);

        $this->log('created', $model, [
            'new' => $model->getAttributes(),
        ]);
    }

    public function updated($model): void
    {
        $this->log('updated', $model, [
            'old'   => $model->getOriginal(),
            'new'   => $model->getAttributes(),
            'dirty' => $model->getChanges(),
        ]);
    }

    public function deleted($model): void
    {
        $this->log('deleted', $model);
    }

    public function restored($model): void
    {
        $this->log('restored', $model);
    }

    public function forceDeleted($model): void
    {
        $this->log('force_deleted', $model);
    }
}
