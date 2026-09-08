<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait HasAuditStamps
{
    public static function bootHasAuditStamps(): void
    {
        static::creating(function ($model) {
            $model->created_by    = Auth::id();
            $model->created_by_ip = Request::ip();
            $model->updated_by    = Auth::id();
            $model->updated_by_ip = Request::ip();
        });

        static::updating(function ($model) {
            $model->updated_by    = Auth::id();
            $model->updated_by_ip = Request::ip();
        });
    }
}