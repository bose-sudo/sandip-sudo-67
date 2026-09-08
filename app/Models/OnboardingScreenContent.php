<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnboardingScreenContent extends Model
{
    use HasFactory;
    protected $table = "onboarding_screen_contents";
    protected $primaryKey = "id";
    
    protected $fillable = [
        "title",
        "content",
        "is_active",
        "is_deleted",
        "deleted_at",
    ];
}
